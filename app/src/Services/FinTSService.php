<?php
declare(strict_types=1);

namespace App\Services;

use Fhp\FinTs;
use Fhp\Options\FinTsOptions;
use Fhp\Options\Credentials;
use Fhp\Model\SEPAAccount;
use Fhp\Action\GetSEPAAccounts;
use Fhp\Action\GetBalance;
use Fhp\Action\GetStatementOfAccount;
use Fhp\Action\GetStatementOfAccountXML;
use Fhp\Action\GetDepotAufstellung;
use Fhp\Model\StatementOfAccount\Transaction;
use Fhp\BaseAction;
use Fhp\CurlException;
use Fhp\Protocol\ServerException;
use Fhp\UnsupportedException;
use Monolog\Logger;
use Exception;

class FinTSService
{
    private Logger $logger;
    private ?FinTs $finTs = null;
    private array $config = [];
    private ?string $productId = null;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Set the FinTS product registration ID
     */
    public function setProductId(?string $productId): void
    {
        $this->productId = $productId;
    }
    
    /**
     * Properly close the dialog and persist the session for future reuse.
     * 
     * IMPORTANT: The persisted instance contains dialogId and messageNumber.
     * If we persist without closing, the next restore will try to continue
     * an already-ended dialog, causing "Nachricht hat nicht die erwartete 
     * Nachrichtennummer" errors.
     * 
     * By calling close() first, the dialogId is set to null, so the next
     * login() will start a fresh dialog while keeping the kundensystemId,
     * BPD, UPD, and TAN mode selection for faster reconnection.
     */
    private function persistAfterClose(): string
    {
        if ($this->finTs !== null) {
            try {
                $this->finTs->close();
                $this->logger->debug('Dialog closed before persisting session');
            } catch (\Throwable $e) {
                $this->logger->warning('Could not close dialog', ['error' => $e->getMessage()]);
            }
        }
        return $this->finTs->persist();
    }

    /**
     * Create FinTS options object
     */
    private function createOptions(array $bankConfig): FinTsOptions
    {
        $options = new FinTsOptions();
        $options->url = $bankConfig['fints_url'];
        $options->bankCode = $bankConfig['bank_code'];
        
        // Use registered product ID or fallback
        if (!empty($this->productId)) {
            $options->productName = $this->productId;
            $options->productVersion = '1.0.0';
        } else {
            throw new Exception('FinTS Produkt-ID nicht konfiguriert. Bitte in den Einstellungen hinterlegen.');
        }
        
        return $options;
    }

    /**
     * Create credentials object
     */
    private function createCredentials(array $bankConfig): Credentials
    {
        return Credentials::create($bankConfig['username'], $bankConfig['password']);
    }

    /**
     * Initialize FinTS connection
     */
    public function init(array $bankConfig): void
    {
        $this->config = $bankConfig;
        
        $options = $this->createOptions($bankConfig);
        $credentials = $this->createCredentials($bankConfig);
        
        $this->finTs = FinTs::new($options, $credentials);

        $this->logger->info('FinTS initialized', [
            'bank_code' => $bankConfig['bank_code'],
            'url' => $bankConfig['fints_url']
        ]);
    }

    /**
     * Test connection to bank
     */
    public function testConnection(array $bankConfig): array
    {
        try {
            $this->init($bankConfig);
            
            // Get TAN modes (this tests the connection)
            $tanModes = $this->finTs->getTanModes();
            
            $this->finTs->close();
            
            return [
                'success' => true,
                'message' => 'Verbindung erfolgreich',
                'tan_modes' => array_map(function($mode) {
                    return [
                        'id' => $mode->getId(),
                        'name' => $mode->getName(),
                    ];
                }, $tanModes)
            ];
        } catch (CurlException $e) {
            $this->logger->error('Connection failed (CURL)', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Verbindungsfehler: ' . $e->getMessage()
            ];
        } catch (ServerException $e) {
            $this->logger->error('Connection failed (Server)', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Bankfehler: ' . $e->getMessage()
            ];
        } catch (Exception $e) {
            $this->logger->error('Connection failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Fehler: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Select the first available TAN mode and medium if required
     */
    private function selectTanMode(): void
    {
        $tanModes = $this->finTs->getTanModes();
        if (empty($tanModes)) {
            $this->logger->warning('No TAN modes available');
            return;
        }

        // Select the first available TAN mode
        $selectedMode = reset($tanModes);
        
        // Check if TAN medium is required for this mode
        if (method_exists($selectedMode, 'needsTanMedium') && $selectedMode->needsTanMedium()) {
            $this->logger->info('TAN medium required, fetching available media');
            try {
                $tanMedia = $this->finTs->getTanMedia($selectedMode);
                if (!empty($tanMedia)) {
                    $selectedMedium = reset($tanMedia);
                    // In newer phpFinTS, pass medium as second parameter to selectTanMode
                    $this->finTs->selectTanMode($selectedMode, $selectedMedium);
                    $this->logger->info('Selected TAN mode with medium', [
                        'mode' => $selectedMode->getName(),
                        'medium' => $selectedMedium->getName()
                    ]);
                    return;
                } else {
                    $this->logger->warning('No TAN media available, selecting mode without medium');
                }
            } catch (Exception $e) {
                $this->logger->error('Failed to get TAN media', ['error' => $e->getMessage()]);
            }
        }

        // Select TAN mode without medium
        $this->finTs->selectTanMode($selectedMode);
        $this->logger->info('Selected TAN mode', ['mode' => $selectedMode->getName()]);
    }

    /**
     * Get available SEPA accounts
     */
    public function getAccounts(array $bankConfig, ?string $persistedInstance = null): array
    {
        try {
            return $this->doGetAccounts($bankConfig, $persistedInstance);
        } catch (Exception $e) {
            // If session-related error and we had a persisted instance, retry with fresh session
            $errorMessage = $e->getMessage();
            if ($persistedInstance && (
                strpos($errorMessage, 'Dialogkontext') !== false ||
                strpos($errorMessage, 'Dialog') !== false ||
                strpos($errorMessage, 'session') !== false
            )) {
                $this->logger->info('Session expired, starting fresh connection');
                try {
                    return $this->doGetAccounts($bankConfig, null);
                } catch (Exception $retryException) {
                    $this->logger->error('Retry also failed', ['error' => $retryException->getMessage()]);
                    return [
                        'success' => false,
                        'message' => 'Fehler beim Abrufen der Konten: ' . $retryException->getMessage()
                    ];
                }
            }
            
            $this->logger->error('Failed to get accounts', ['error' => $errorMessage]);
            return [
                'success' => false,
                'message' => 'Fehler beim Abrufen der Konten: ' . $errorMessage
            ];
        }
    }

    /**
     * Internal method to get accounts
     */
    private function doGetAccounts(array $bankConfig, ?string $persistedInstance): array
    {
        $options = $this->createOptions($bankConfig);
        $credentials = $this->createCredentials($bankConfig);
        
        if ($persistedInstance) {
            $this->logger->info('Reusing persisted FinTS instance');
            $this->finTs = FinTs::new($options, $credentials, $persistedInstance);
        } else {
            $this->logger->info('Creating new FinTS instance');
            $this->finTs = FinTs::new($options, $credentials);
            // Select TAN mode before login (required by phpFinTS)
            $this->selectTanMode();
        }

        // Login first (required before any other action)
        $login = $this->finTs->login();
        if ($login->needsTan()) {
            $this->logger->info('Login requires TAN');
            return [
                'success' => false,
                'needs_tan' => true,
                'tan_request' => $this->extractTanRequest($login),
                'persisted_action' => base64_encode(serialize($login)),
                'persisted_instance' => $this->finTs->persist()
            ];
        }

        $this->logger->info('Login successful without TAN');

        $getSepaAccounts = GetSEPAAccounts::create();
        $this->finTs->execute($getSepaAccounts);

        if ($getSepaAccounts->needsTan()) {
            $this->logger->info('GetSEPAAccounts requires TAN');
            return [
                'success' => false,
                'needs_tan' => true,
                'tan_request' => $this->extractTanRequest($getSepaAccounts),
                'persisted_action' => base64_encode(serialize($getSepaAccounts)),
                'persisted_instance' => $this->finTs->persist()
            ];
        }

        $this->logger->info('Accounts fetched successfully without TAN');
        return $this->processAccountsResult($getSepaAccounts);
    }

    /**
     * Get account balance using GetBalance action
     * Based on phpFinTS: GetBalance->getBalances() returns HISAL[]
     * HISAL->getGebuchterSaldo() returns Sdo with getAmount(), getCurrency(), getTimestamp()
     */
    private function getAccountBalance(SEPAAccount $account): ?array
    {
        try {
            $getBalance = GetBalance::create($account);
            $this->finTs->execute($getBalance);

            if (!$getBalance->needsTan()) {
                $balances = $getBalance->getBalances();
                if (!empty($balances)) {
                    // Get first HISAL segment
                    $hisal = reset($balances);
                    
                    // Get booked balance (gebuchter Saldo)
                    $saldo = $hisal->getGebuchterSaldo();
                    
                    if ($saldo) {
                        $amount = $saldo->getAmount();
                        $currency = $saldo->getCurrency();
                        $timestamp = $saldo->getTimestamp();
                        
                        $this->logger->debug('Got balance from bank', [
                            'amount' => $amount,
                            'currency' => $currency,
                            'timestamp' => $timestamp ? $timestamp->format('Y-m-d H:i:s') : null
                        ]);
                        
                        return [
                            'amount' => $amount,
                            'currency' => $currency,
                            'date' => $timestamp ? $timestamp->format('Y-m-d H:i:s') : date('Y-m-d H:i:s')
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            $this->logger->debug('Balance retrieval failed', ['error' => $e->getMessage()]);
        }

        return null;
    }
    
    /**
     * Fetch balances for all accounts of a bank
     * This is a dedicated method to refresh account balances
     */
    public function fetchAccountBalances(array $bankConfig, ?string $persistedInstance = null): array
    {
        $this->logger->info('=== fetchAccountBalances START ===');
        
        try {
            $options = $this->createOptions($bankConfig);
            $credentials = $this->createCredentials($bankConfig);
            
            if ($persistedInstance) {
                $this->finTs = FinTs::new($options, $credentials, $persistedInstance);
            } else {
                $this->finTs = FinTs::new($options, $credentials);
                $this->selectTanMode();
            }
            
            // Login
            $login = $this->finTs->login();
            if ($login->needsTan()) {
                $this->logger->info('Login requires TAN for balance fetch');
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($login),
                    'persisted_action' => base64_encode(serialize($login)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }
            
            // Get SEPA accounts
            $getSepaAccounts = GetSEPAAccounts::create();
            $this->finTs->execute($getSepaAccounts);
            
            if ($getSepaAccounts->needsTan()) {
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($getSepaAccounts),
                    'persisted_action' => base64_encode(serialize($getSepaAccounts)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }
            
            $accounts = $getSepaAccounts->getAccounts();
            $balances = [];
            
            foreach ($accounts as $account) {
                $iban = $account->getIban();
                $accountNumber = $account->getAccountNumber();
                
                // Skip depots (they don't have a balance in the traditional sense)
                if (empty($iban)) {
                    continue;
                }
                
                try {
                    $balance = $this->getAccountBalance($account);
                    if ($balance !== null) {
                        $balances[] = [
                            'iban' => $iban,
                            'account_number' => $accountNumber,
                            'balance' => $balance['amount'],
                            'currency' => $balance['currency'] ?? 'EUR',
                            'balance_date' => $balance['date']
                        ];
                        
                        $this->logger->info('Fetched balance', [
                            'iban' => $iban,
                            'balance' => $balance['amount']
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Could not get balance for account', [
                        'iban' => $iban,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            return [
                'success' => true,
                'balances' => $balances,
                'persisted_instance' => $this->persistAfterClose()
            ];
            
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $this->logger->error('fetchAccountBalances failed', ['error' => $errorMessage]);
            
            // If session-related error and we had a persisted instance, retry with fresh session
            if ($persistedInstance !== null && (
                strpos($errorMessage, 'Dialogkontext') !== false ||
                strpos($errorMessage, 'Dialog') !== false ||
                strpos($errorMessage, 'session') !== false ||
                strpos($errorMessage, 'Need to login') !== false
            )) {
                $this->logger->info('Session appears expired, retrying with fresh connection');
                return $this->fetchAccountBalances($bankConfig, null);
            }
            
            return [
                'success' => false,
                'message' => 'Fehler: ' . $errorMessage
            ];
        }
    }
    
    /**
     * Sync everything for a bank in one session:
     * - Account balances
     * - Transactions for all regular accounts
     * - Holdings for all depots
     * 
     * @param array $bankConfig Bank configuration
     * @param array $accountsFromDb Accounts to sync
     * @param string|null $persistedInstance Persisted FinTS session (preserves kundensystemId for TAN-free access)
     */
    public function syncAll(array $bankConfig, array $accountsFromDb, ?string $persistedInstance = null): array
    {
        $this->logger->info('=== syncAll START ===', [
            'accounts_count' => count($accountsFromDb),
            'has_persisted_instance' => $persistedInstance !== null
        ]);
        
        $results = [
            'balances' => [],
            'transactions' => [],
            'holdings' => [],
            'errors' => []
        ];
        
        try {
            $options = $this->createOptions($bankConfig);
            $credentials = $this->createCredentials($bankConfig);
            
            // Use persisted instance if available (preserves kundensystemId for PSD2 90-day TAN-free access)
            if ($persistedInstance !== null) {
                $this->logger->info('=== RESTORING PERSISTED SESSION ===', [
                    'data_length' => strlen($persistedInstance)
                ]);
                $this->finTs = FinTs::new($options, $credentials, $persistedInstance);
                
                // Log what was restored
                try {
                    $bpd = $this->finTs->getBpd();
                    $this->logger->info('Session restored successfully', [
                        'has_bpd' => $bpd !== null,
                        'bank_name' => $bpd ? ($bpd->bankName ?? 'unknown') : 'no BPD'
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Could not get BPD from restored session', ['error' => $e->getMessage()]);
                }
                // Don't re-select TAN mode when restoring - it's already in the persisted state
            } else {
                $this->logger->info('=== CREATING NEW SESSION ===');
                $this->finTs = FinTs::new($options, $credentials);
                // Select TAN mode only for new sessions
                $this->selectTanMode();
            }
            
            // Login
            $login = $this->finTs->login();
            if ($login->needsTan()) {
                $this->logger->info('Login requires TAN for full sync');
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($login),
                    'persisted_action' => base64_encode(serialize($login)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }
            
            // Get SEPA accounts
            $getSepaAccounts = GetSEPAAccounts::create();
            $this->finTs->execute($getSepaAccounts);
            
            if ($getSepaAccounts->needsTan()) {
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($getSepaAccounts),
                    'persisted_action' => base64_encode(serialize($getSepaAccounts)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }
            
            $sepaAccounts = $getSepaAccounts->getAccounts();
            $this->logger->info('Got SEPA accounts', ['count' => count($sepaAccounts)]);
            
            // Log all SEPA accounts for debugging
            foreach ($sepaAccounts as $idx => $acc) {
                $subAcc = method_exists($acc, 'getSubAccount') ? $acc->getSubAccount() : null;
                $this->logger->debug('SEPA account', [
                    'index' => $idx,
                    'iban' => $acc->getIban(),
                    'account_number' => $acc->getAccountNumber(),
                    'sub_account' => $subAcc
                ]);
            }
            
            // Process each account from database
            foreach ($accountsFromDb as $dbAccount) {
                $accountId = $dbAccount['id'];
                $iban = $dbAccount['iban'];
                $accountNumber = $dbAccount['account_number'];
                $subAccount = $dbAccount['sub_account'] ?? null;
                $accountType = $dbAccount['account_type'] ?? 'checking';
                $accountName = $dbAccount['account_name'] ?? 'Unbekannt';
                
                $this->logger->info('Processing DB account', [
                    'id' => $accountId,
                    'name' => $accountName,
                    'type' => $accountType,
                    'iban' => $iban,
                    'account_number' => $accountNumber,
                    'sub_account' => $subAccount
                ]);
                
                // Find matching SEPA account - for depots, also check sub_account
                $sepaAccount = null;
                foreach ($sepaAccounts as $acc) {
                    $accIban = $acc->getIban();
                    $accNum = $acc->getAccountNumber();
                    $accSubNum = method_exists($acc, 'getSubAccount') ? $acc->getSubAccount() : null;
                    
                    // Match by IBAN (for regular accounts)
                    if (!empty($iban) && $accIban === $iban) {
                        $sepaAccount = $acc;
                        break;
                    }
                    
                    // Match by account number + sub-account (for depots)
                    if ($accNum === $accountNumber) {
                        // If both have sub-accounts, they must match
                        if ($subAccount && $accSubNum) {
                            if ($subAccount === $accSubNum) {
                                $sepaAccount = $acc;
                                break;
                            }
                        }
                        // If DB account has no sub-account, match on account number only
                        elseif (!$subAccount) {
                            $sepaAccount = $acc;
                            break;
                        }
                    }
                }
                
                if (!$sepaAccount) {
                    $this->logger->warning('Could not find matching SEPA account', [
                        'iban' => $iban, 
                        'account_number' => $accountNumber,
                        'sub_account' => $subAccount
                    ]);
                    $results['errors'][] = "Konto '{$accountName}' nicht gefunden in FinTS-Antwort";
                    continue;
                }
                
                $this->logger->info('Matched SEPA account', [
                    'db_id' => $accountId,
                    'sepa_iban' => $sepaAccount->getIban(),
                    'sepa_num' => $sepaAccount->getAccountNumber()
                ]);
                
                if ($accountType === 'depot') {
                    // Fetch depot holdings
                    $this->logger->info('Fetching depot holdings', ['account_id' => $accountId]);
                    try {
                        $holdingsResult = $this->fetchDepotHoldingsInternal($sepaAccount);
                        if ($holdingsResult['success']) {
                            $results['holdings'][$accountId] = $holdingsResult['holdings'];
                            $this->logger->info('Got depot holdings', [
                                'account_id' => $accountId,
                                'count' => count($holdingsResult['holdings'])
                            ]);
                        } else {
                            $results['errors'][] = "Depot {$accountId}: " . ($holdingsResult['message'] ?? 'Unbekannter Fehler');
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to fetch depot holdings', [
                            'account_id' => $accountId,
                            'error' => $e->getMessage()
                        ]);
                        $results['errors'][] = "Depot {$accountId}: " . $e->getMessage();
                    }
                } else {
                    // Fetch balance
                    $this->logger->info('Fetching balance', ['account_id' => $accountId, 'iban' => $iban]);
                    try {
                        $balance = $this->getAccountBalance($sepaAccount);
                        if ($balance) {
                            $results['balances'][$accountId] = $balance;
                            $this->logger->info('Got balance', [
                                'account_id' => $accountId,
                                'balance' => $balance['amount']
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to fetch balance', [
                            'account_id' => $accountId,
                            'error' => $e->getMessage()
                        ]);
                    }
                    
                    // Fetch transactions (last 30 days)
                    $this->logger->info('Fetching transactions', ['account_id' => $accountId]);
                    try {
                        $from = new \DateTime('-30 days');
                        $to = new \DateTime();
                        
                        $txResult = $this->fetchTransactionsInternal($sepaAccount, $from, $to);
                        if ($txResult['success']) {
                            $results['transactions'][$accountId] = $txResult['transactions'];
                            $this->logger->info('Got transactions', [
                                'account_id' => $accountId,
                                'count' => count($txResult['transactions'])
                            ]);
                        } else {
                            $results['errors'][] = "Transaktionen {$accountId}: " . ($txResult['message'] ?? 'Unbekannter Fehler');
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to fetch transactions', [
                            'account_id' => $accountId,
                            'error' => $e->getMessage()
                        ]);
                        $results['errors'][] = "Transaktionen {$accountId}: " . $e->getMessage();
                    }
                }
            }
            
            return [
                'success' => true,
                'results' => $results,
                'persisted_instance' => $this->persistAfterClose()
            ];
            
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $this->logger->error('syncAll failed', ['error' => $errorMessage]);
            
            // If session-related error and we had a persisted instance, retry with fresh session
            if ($persistedInstance !== null && (
                strpos($errorMessage, 'Dialogkontext') !== false ||
                strpos($errorMessage, 'Dialog') !== false ||
                strpos($errorMessage, 'session') !== false ||
                strpos($errorMessage, 'Need to login') !== false
            )) {
                $this->logger->info('Session appears expired, retrying with fresh connection');
                return $this->syncAll($bankConfig, $accountsFromDb, null);
            }
            
            return [
                'success' => false,
                'message' => 'Fehler: ' . $errorMessage
            ];
        }
    }
    
    /**
     * Internal method to fetch transactions (used within an existing session)
     */
    private function fetchTransactionsInternal(SEPAAccount $account, \DateTime $from, \DateTime $to): array
    {
        // Check BPD for supported formats
        $supportsMT940 = false;
        $supportsCAMT = false;
        
        try {
            $bpd = $this->finTs->getBpd();
            if ($bpd) {
                $mt940Params = $bpd->parameters['HIKAZS'] ?? [];
                $camtParams = $bpd->parameters['HICAZS'] ?? [];
                
                foreach (array_keys($mt940Params) as $version) {
                    if (in_array((int)$version, [4, 5, 6, 7])) {
                        $supportsMT940 = true;
                        break;
                    }
                }
                
                foreach (array_keys($camtParams) as $version) {
                    if ((int)$version === 1) {
                        $supportsCAMT = true;
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            $supportsMT940 = true;
            $supportsCAMT = true;
        }

        $errors = [];
        
        // Try MT940
        if ($supportsMT940) {
            try {
                $getStatement = GetStatementOfAccount::create($account, $from, $to);
                $this->finTs->execute($getStatement);

                if (!$getStatement->needsTan()) {
                    $result = $this->processTransactionsResult($getStatement);
                    if ($result['success'] && count($result['transactions'] ?? []) > 0) {
                        return $result;
                    }
                }
            } catch (\Throwable $e) {
                $errors['MT940'] = $e->getMessage();
            }
        }

        // Try CAMT
        if ($supportsCAMT) {
            try {
                $result = $this->fetchTransactionsXML($account, $from, $to);
                if (isset($result['success']) && $result['success']) {
                    return $result;
                }
            } catch (\Throwable $e) {
                $errors['CAMT'] = $e->getMessage();
            }
        }

        return [
            'success' => true,
            'transactions' => [],
            'message' => 'Keine Transaktionen gefunden'
        ];
    }
    
    /**
     * Internal method to fetch depot holdings (used within an existing session)
     */
    private function fetchDepotHoldingsInternal(SEPAAccount $account): array
    {
        if (!class_exists(GetDepotAufstellung::class)) {
            return [
                'success' => false,
                'message' => 'Depot-Abfrage nicht unterstützt'
            ];
        }
        
        try {
            $getDepot = GetDepotAufstellung::create($account);
            $this->finTs->execute($getDepot);
            
            if ($getDepot->needsTan()) {
                return [
                    'success' => false,
                    'message' => 'TAN erforderlich für Depot-Abfrage'
                ];
            }
            
            $holdings = $this->processDepotResult($getDepot);
            
            return [
                'success' => true,
                'holdings' => $holdings
            ];
            
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Submit TAN and continue action
     */
    public function submitTan(array $bankConfig, string $persistedInstance, string $persistedAction, string $tan, ?array $syncContext = null, ?array $syncAllContext = null): array
    {
        try {
            $options = $this->createOptions($bankConfig);
            $credentials = $this->createCredentials($bankConfig);
            $this->finTs = FinTs::new($options, $credentials, $persistedInstance);

            $action = unserialize(base64_decode($persistedAction));
            
            $this->finTs->submitTan($action, $tan);

            if ($action->needsTan()) {
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($action),
                    'persisted_action' => base64_encode(serialize($action)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }

            // Action completed - check what type of action it was
            if ($action instanceof GetSEPAAccounts) {
                return $this->processAccountsResult($action);
            }

            // Handle completed transaction fetch (MT940)
            if ($action instanceof GetStatementOfAccount) {
                $this->logger->info('TAN accepted for GetStatementOfAccount - processing transactions');
                $result = $this->processTransactionsResult($action);
                $result['persisted_instance'] = $this->persistAfterClose();
                return $result;
            }

            // Handle completed transaction fetch (CAMT XML)
            if ($action instanceof GetStatementOfAccountXML) {
                $this->logger->info('TAN accepted for GetStatementOfAccountXML - processing transactions');
                $result = $this->processTransactionsXMLResult($action);
                $result['persisted_instance'] = $this->persistAfterClose();
                return $result;
            }

            // If this was a login action, now fetch the accounts
            // Check if this is a login/dialog action by checking if we can now get accounts
            try {
                $getSepaAccounts = GetSEPAAccounts::create();
                $this->finTs->execute($getSepaAccounts);

                if ($getSepaAccounts->needsTan()) {
                    return [
                        'success' => false,
                        'needs_tan' => true,
                        'tan_request' => $this->extractTanRequest($getSepaAccounts),
                        'persisted_action' => base64_encode(serialize($getSepaAccounts)),
                        'persisted_instance' => $this->finTs->persist()
                    ];
                }

                // If we have a syncAll context, continue with full sync in this session
                if ($syncAllContext !== null && !empty($syncAllContext)) {
                    $this->logger->info('=== Continuing with FULL SYNC after TAN ===', [
                        'accounts_count' => count($syncAllContext)
                    ]);
                    return $this->continueSyncAllAfterTan($getSepaAccounts->getAccounts(), $syncAllContext);
                }

                // If we have a sync context, continue with transaction fetching in this session
                if ($syncContext !== null) {
                    $this->logger->info('Continuing with transaction sync after TAN', $syncContext);
                    return $this->continueWithTransactionFetch(
                        $getSepaAccounts->getAccounts(),
                        $syncContext['account_identifier'],
                        $syncContext['from'],
                        $syncContext['to']
                    );
                }

                return $this->processAccountsResult($getSepaAccounts);
            } catch (Exception $e) {
                // If getting accounts fails after login TAN, just report success
                $this->logger->info('Login TAN accepted, but could not fetch accounts', ['error' => $e->getMessage()]);
                return [
                    'success' => true,
                    'message' => 'TAN akzeptiert - bitte Konten erneut abrufen'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('TAN submission failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'TAN-Fehler: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process accounts result from GetSEPAAccounts action
     */
    private function processAccountsResult(GetSEPAAccounts $action): array
    {
        $accounts = $action->getAccounts();
        $result = [];

        foreach ($accounts as $account) {
            // Build account name from available data
            $iban = $account->getIban();
            $accountNumber = $account->getAccountNumber();
            $subAccount = method_exists($account, 'getSubAccount') ? $account->getSubAccount() : null;
            
            // Detect account type based on available information
            $accountType = $this->detectAccountType($account);
            
            // Generate appropriate account name
            if ($accountType === 'depot') {
                $accountName = 'Depot ' . ($subAccount ?: substr($accountNumber, -4));
            } else {
                $accountName = $iban ? 'Konto ' . substr($iban, -4) : 'Konto ' . $accountNumber;
            }
            
            $accountData = [
                'account_number' => $accountNumber,
                'iban' => $iban,
                'bic' => $account->getBic(),
                'account_name' => $accountName,
                'owner_name' => null, // Not available in SEPAAccount
                'account_type' => $accountType,
                'sub_account' => $subAccount,
                'currency' => 'EUR',
                'balance' => null,
                'balance_date' => null
            ];

            // Try to get balance (only for non-depot accounts)
            if ($accountType !== 'depot') {
                try {
                    $balance = $this->getAccountBalance($account);
                    if ($balance !== null) {
                        $accountData['balance'] = $balance['amount'];
                        $accountData['balance_date'] = $balance['date'];
                    }
                } catch (Exception $e) {
                    $this->logger->warning('Could not get balance', [
                        'account' => $accountNumber,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $result[] = $accountData;
        }

        return [
            'success' => true,
            'accounts' => $result,
            'persisted_instance' => $this->persistAfterClose()
        ];
    }
    
    /**
     * Detect account type based on SEPAAccount properties
     */
    private function detectAccountType(SEPAAccount $account): string
    {
        $iban = $account->getIban();
        $accountNumber = $account->getAccountNumber();
        $subAccount = method_exists($account, 'getSubAccount') ? $account->getSubAccount() : null;
        
        // Depots typically don't have an IBAN
        if (empty($iban)) {
            // Check if it looks like a depot number (often numeric, sometimes with leading zeros)
            if ($subAccount || preg_match('/^[0-9]+$/', $accountNumber)) {
                return 'depot';
            }
        }
        
        // Check BPD to see if depot operations are supported for this account
        // This is a heuristic - accounts without IBAN that support HIWPDS are likely depots
        try {
            $bpd = $this->finTs->getBpd();
            if ($bpd && isset($bpd->parameters['HIWPDS']) && empty($iban)) {
                return 'depot';
            }
        } catch (Exception $e) {
            // Ignore BPD errors
        }
        
        return 'checking';
    }

    /**
     * Check if the selected TAN mode is decoupled
     */
    private function isDecoupledMode(): bool
    {
        if ($this->finTs === null) {
            return false;
        }
        
        try {
            $tanMode = $this->finTs->getSelectedTanMode();
            if ($tanMode && method_exists($tanMode, 'isDecoupled')) {
                return $tanMode->isDecoupled();
            }
        } catch (Exception $e) {
            $this->logger->debug('Could not check TAN mode', ['error' => $e->getMessage()]);
        }
        
        return false;
    }

    /**
     * Extract TAN request information
     */
    private function extractTanRequest($action): array
    {
        $tanRequest = $action->getTanRequest();
        
        // Check if the selected TAN mode is decoupled (app confirmation without TAN input)
        $isDecoupled = $this->isDecoupledMode();
        
        $challenge = $tanRequest->getChallenge();
        
        $this->logger->info('TAN request extracted', [
            'is_decoupled' => $isDecoupled,
            'challenge' => substr($challenge ?? '', 0, 100)
        ]);
        
        return [
            'challenge' => $challenge,
            'challenge_html' => method_exists($tanRequest, 'getChallengeHtml') ? $tanRequest->getChallengeHtml() : null,
            'tan_medium' => method_exists($tanRequest, 'getTanMediumName') ? $tanRequest->getTanMediumName() : null,
            'is_decoupled' => $isDecoupled
        ];
    }

    /**
     * Check decoupled TAN status (for pushTAN app confirmation)
     * 
     * @param array $bankConfig Bank configuration
     * @param string $persistedInstance Persisted FinTS instance
     * @param string $persistedAction Persisted action waiting for TAN
     * @param array|null $syncContext If set, continue with transaction sync after TAN confirmation
     *                                ['account_identifier' => string, 'from' => DateTime, 'to' => DateTime]
     * @param array|null $syncAllContext If set, continue with full sync after TAN confirmation
     *                                   Array of accounts from database to sync
     */
    public function checkDecoupledStatus(array $bankConfig, string $persistedInstance, string $persistedAction, ?array $syncContext = null, ?array $syncAllContext = null): array
    {
        try {
            $options = $this->createOptions($bankConfig);
            $credentials = $this->createCredentials($bankConfig);
            $this->finTs = FinTs::new($options, $credentials, $persistedInstance);

            $action = unserialize(base64_decode($persistedAction));
            
            // Check if the decoupled authentication was completed
            $status = $this->finTs->checkDecoupledSubmission($action);

            if ($action->needsTan()) {
                // Still waiting for confirmation
                return [
                    'success' => false,
                    'status' => 'pending',
                    'message' => 'Warte auf Bestätigung in der Banking-App...',
                    'persisted_action' => base64_encode(serialize($action)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }

            // Confirmation received - process the result
            if ($action instanceof GetSEPAAccounts) {
                return $this->processAccountsResult($action);
            }

            // Handle completed transaction fetch (MT940)
            if ($action instanceof GetStatementOfAccount) {
                $this->logger->info('Decoupled TAN accepted for GetStatementOfAccount - processing transactions');
                $result = $this->processTransactionsResult($action);
                $result['persisted_instance'] = $this->persistAfterClose();
                return $result;
            }

            // Handle completed transaction fetch (CAMT XML)
            if ($action instanceof GetStatementOfAccountXML) {
                $this->logger->info('Decoupled TAN accepted for GetStatementOfAccountXML - processing transactions');
                $result = $this->processTransactionsXMLResult($action);
                $result['persisted_instance'] = $this->persistAfterClose();
                return $result;
            }

            // If this was a login action, now fetch the accounts
            try {
                $getSepaAccounts = GetSEPAAccounts::create();
                $this->finTs->execute($getSepaAccounts);

                if ($getSepaAccounts->needsTan()) {
                    return [
                        'success' => false,
                        'needs_tan' => true,
                        'tan_request' => $this->extractTanRequest($getSepaAccounts),
                        'persisted_action' => base64_encode(serialize($getSepaAccounts)),
                        'persisted_instance' => $this->finTs->persist()
                    ];
                }

                // If we have a syncAll context, continue with full sync in this session
                if ($syncAllContext !== null && !empty($syncAllContext)) {
                    $this->logger->info('=== Continuing with FULL SYNC after TAN ===', [
                        'accounts_count' => count($syncAllContext)
                    ]);
                    return $this->continueSyncAllAfterTan($getSepaAccounts->getAccounts(), $syncAllContext);
                }

                // If we have a sync context, continue with transaction fetching in this session
                if ($syncContext !== null) {
                    $this->logger->info('Continuing with transaction sync in same session', $syncContext);
                    return $this->continueWithTransactionFetch(
                        $getSepaAccounts->getAccounts(),
                        $syncContext['account_identifier'],
                        $syncContext['from'],
                        $syncContext['to']
                    );
                }

                return $this->processAccountsResult($getSepaAccounts);
            } catch (Exception $e) {
                $this->logger->info('Decoupled auth accepted, but could not fetch accounts', ['error' => $e->getMessage()]);
                return [
                    'success' => true,
                    'message' => 'Bestätigung erfolgreich - bitte Konten erneut abrufen'
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Decoupled check failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Fehler bei Statusabfrage: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Continue the full sync operation after TAN confirmation
     * Uses the already-authenticated session to fetch all data
     */
    private function continueSyncAllAfterTan(array $sepaAccounts, array $accountsFromDb): array
    {
        $this->logger->info('=== continueSyncAllAfterTan START ===', [
            'sepa_accounts' => count($sepaAccounts),
            'db_accounts' => count($accountsFromDb)
        ]);
        
        $results = [
            'balances' => [],
            'transactions' => [],
            'holdings' => [],
            'errors' => []
        ];
        
        $from = new \DateTime('-30 days');
        $to = new \DateTime();
        
        // Log all SEPA accounts for debugging
        foreach ($sepaAccounts as $idx => $acc) {
            $subAcc = method_exists($acc, 'getSubAccount') ? $acc->getSubAccount() : null;
            $this->logger->debug('SEPA account available', [
                'index' => $idx,
                'iban' => $acc->getIban(),
                'account_number' => $acc->getAccountNumber(),
                'sub_account' => $subAcc
            ]);
        }
        
        // Process each account from database
        foreach ($accountsFromDb as $dbAccount) {
            $accountId = $dbAccount['id'];
            $iban = $dbAccount['iban'];
            $accountNumber = $dbAccount['account_number'];
            $subAccount = $dbAccount['sub_account'] ?? null;
            $accountType = $dbAccount['account_type'] ?? 'checking';
            $accountName = $dbAccount['account_name'] ?? 'Unbekannt';
            
            $this->logger->info('Processing account in continueSyncAll', [
                'id' => $accountId,
                'name' => $accountName,
                'type' => $accountType,
                'iban' => $iban,
                'account_number' => $accountNumber,
                'sub_account' => $subAccount
            ]);
            
            // Find matching SEPA account
            $sepaAccount = $this->findMatchingSepaAccount($sepaAccounts, $dbAccount);
            
            if (!$sepaAccount) {
                $this->logger->warning('No matching SEPA account found', [
                    'account_id' => $accountId,
                    'account_name' => $accountName
                ]);
                $results['errors'][] = [
                    'account_id' => $accountId,
                    'error' => 'Kein passendes SEPA-Konto gefunden'
                ];
                continue;
            }
            
            $this->logger->info('Found matching SEPA account', [
                'db_account_id' => $accountId,
                'sepa_iban' => $sepaAccount->getIban(),
                'sepa_account_number' => $sepaAccount->getAccountNumber()
            ]);
            
            if ($accountType === 'depot') {
                // Fetch holdings for depots
                try {
                    $this->logger->info('Fetching depot holdings', ['account_id' => $accountId]);
                    $holdingsResult = $this->fetchDepotHoldingsInternal($sepaAccount);
                    
                    if ($holdingsResult['success'] && !empty($holdingsResult['holdings'])) {
                        $results['holdings'][$accountId] = $holdingsResult['holdings'];
                        $this->logger->info('Depot holdings fetched', [
                            'account_id' => $accountId,
                            'count' => count($holdingsResult['holdings'])
                        ]);
                    } elseif (!$holdingsResult['success']) {
                        $results['errors'][] = [
                            'account_id' => $accountId,
                            'error' => 'Depot: ' . ($holdingsResult['message'] ?? 'Unbekannter Fehler')
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to fetch holdings in continueSyncAll', [
                        'account_id' => $accountId,
                        'error' => $e->getMessage()
                    ]);
                    $results['errors'][] = [
                        'account_id' => $accountId,
                        'error' => 'Depot: ' . $e->getMessage()
                    ];
                }
            } else {
                // Fetch balance for regular accounts
                try {
                    $this->logger->info('Fetching balance', ['account_id' => $accountId]);
                    $balance = $this->getAccountBalance($sepaAccount);
                    
                    if ($balance !== null) {
                        $results['balances'][$accountId] = $balance;
                        $this->logger->info('Balance fetched', [
                            'account_id' => $accountId,
                            'amount' => $balance['amount']
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to fetch balance in continueSyncAll', [
                        'account_id' => $accountId,
                        'error' => $e->getMessage()
                    ]);
                    $results['errors'][] = [
                        'account_id' => $accountId,
                        'error' => 'Saldo: ' . $e->getMessage()
                    ];
                }
                
                // Fetch transactions for regular accounts
                try {
                    $this->logger->info('Fetching transactions', ['account_id' => $accountId]);
                    $txResult = $this->fetchTransactionsInternal($sepaAccount, $from, $to);
                    
                    if ($txResult['success'] && !empty($txResult['transactions'])) {
                        $results['transactions'][$accountId] = $txResult['transactions'];
                        $this->logger->info('Transactions fetched', [
                            'account_id' => $accountId,
                            'count' => count($txResult['transactions'])
                        ]);
                    } elseif (!$txResult['success']) {
                        $results['errors'][] = [
                            'account_id' => $accountId,
                            'error' => 'Transaktionen: ' . ($txResult['message'] ?? 'Unbekannter Fehler')
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to fetch transactions in continueSyncAll', [
                        'account_id' => $accountId,
                        'error' => $e->getMessage()
                    ]);
                    $results['errors'][] = [
                        'account_id' => $accountId,
                        'error' => 'Transaktionen: ' . $e->getMessage()
                    ];
                }
            }
        }
        
        $this->logger->info('=== continueSyncAllAfterTan COMPLETE ===', [
            'balances' => count($results['balances']),
            'transactions_accounts' => count($results['transactions']),
            'holdings_accounts' => count($results['holdings']),
            'errors' => count($results['errors'])
        ]);
        
        return [
            'success' => true,
            'is_sync_all' => true,
            'results' => $results,
            'persisted_instance' => $this->persistAfterClose()
        ];
    }
    
    /**
     * Find matching SEPA account for a database account
     */
    private function findMatchingSepaAccount(array $sepaAccounts, array $dbAccount): ?SEPAAccount
    {
        $iban = $dbAccount['iban'] ?? null;
        $accountNumber = $dbAccount['account_number'] ?? null;
        $subAccount = $dbAccount['sub_account'] ?? null;
        $accountType = $dbAccount['account_type'] ?? 'checking';
        
        foreach ($sepaAccounts as $acc) {
            $accIban = $acc->getIban();
            $accNum = $acc->getAccountNumber();
            $accSubNum = method_exists($acc, 'getSubAccount') ? $acc->getSubAccount() : null;
            
            // Match by IBAN (for regular accounts)
            if (!empty($iban) && $accIban === $iban) {
                return $acc;
            }
            
            // Match by account number + sub-account (for depots)
            if ($accNum === $accountNumber) {
                // If both have sub-accounts, they must match
                if ($subAccount && $accSubNum) {
                    if ($subAccount === $accSubNum) {
                        return $acc;
                    }
                }
                // If DB account has sub-account but SEPA doesn't expose it, 
                // check if sub-account might be part of account number
                elseif ($subAccount && !$accSubNum) {
                    // Some banks include sub-account in the account number
                    if (strpos($accNum, $subAccount) !== false) {
                        return $acc;
                    }
                    // For depots, sub-account match is critical
                    if ($accountType === 'depot') {
                        continue;
                    }
                }
                // If no sub-account on either side, match on account number
                elseif (!$subAccount) {
                    return $acc;
                }
            }
            
            // Special case: sub-account might be stored in account_number field
            if ($accountType === 'depot' && $subAccount && $accNum === $subAccount) {
                return $acc;
            }
        }
        
        return null;
    }
    
    /**
     * Continue with transaction fetch after successful login/account fetch
     * Called within the same FinTS session
     */
    private function continueWithTransactionFetch(array $sepaAccounts, string $accountIdentifier, \DateTime $from, \DateTime $to): array
    {
        $this->logger->info('=== continueWithTransactionFetch START ===', [
            'account' => $accountIdentifier,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d')
        ]);
        
        // Find the matching account
        $sepaAccount = null;
        foreach ($sepaAccounts as $acc) {
            if ($acc->getIban() === $accountIdentifier || $acc->getAccountNumber() === $accountIdentifier) {
                $sepaAccount = $acc;
                break;
            }
        }

        if (!$sepaAccount) {
            $this->logger->error('Account not found for sync', ['identifier' => $accountIdentifier]);
            return [
                'success' => false,
                'message' => 'Konto nicht gefunden: ' . $accountIdentifier
            ];
        }

        $this->logger->info('Found account, fetching transactions', ['iban' => $sepaAccount->getIban()]);

        // Check BPD for supported formats
        $supportsMT940 = false;
        $supportsCAMT = false;
        
        try {
            $bpd = $this->finTs->getBpd();
            if ($bpd) {
                $mt940Params = $bpd->parameters['HIKAZS'] ?? [];
                $camtParams = $bpd->parameters['HICAZS'] ?? [];
                
                foreach (array_keys($mt940Params) as $version) {
                    if (in_array((int)$version, [4, 5, 6, 7])) {
                        $supportsMT940 = true;
                        break;
                    }
                }
                
                foreach (array_keys($camtParams) as $version) {
                    if ((int)$version === 1) {
                        $supportsCAMT = true;
                        break;
                    }
                }
                
                $this->logger->info('Format support', ['mt940' => $supportsMT940, 'camt' => $supportsCAMT]);
            }
        } catch (Exception $e) {
            $this->logger->warning('Could not check BPD', ['error' => $e->getMessage()]);
            $supportsMT940 = true;
            $supportsCAMT = true;
        }

        $errors = [];
        $mt940Result = null;
        
        // Try MT940
        if ($supportsMT940) {
            $this->logger->info('Trying MT940 format');
            try {
                $getStatement = GetStatementOfAccount::create($sepaAccount, $from, $to);
                $this->finTs->execute($getStatement);

                if ($getStatement->needsTan()) {
                    $this->logger->info('MT940 requires TAN');
                    return [
                        'success' => false,
                        'needs_tan' => true,
                        'tan_request' => $this->extractTanRequest($getStatement),
                        'persisted_action' => base64_encode(serialize($getStatement)),
                        'persisted_instance' => $this->finTs->persist()
                    ];
                }

                $mt940Result = $this->processTransactionsResult($getStatement);
                if ($mt940Result['success']) {
                    $txCount = count($mt940Result['transactions'] ?? []);
                    $this->logger->info('MT940 successful', ['transactions' => $txCount]);
                    
                    if ($txCount > 0) {
                        return $mt940Result;
                    }
                    $this->logger->info('MT940 returned 0 transactions, trying CAMT');
                } else {
                    $errors['MT940'] = $mt940Result['message'] ?? 'Unbekannter Fehler';
                }
            } catch (\Throwable $e) {
                $errors['MT940'] = $e->getMessage();
                $this->logger->info('MT940 failed', ['error' => $e->getMessage()]);
            }
        }

        // Try CAMT
        $camtResult = null;
        if ($supportsCAMT) {
            $this->logger->info('Trying CAMT XML format');
            try {
                $camtResult = $this->fetchTransactionsXML($sepaAccount, $from, $to);
                
                if (isset($camtResult['success']) && $camtResult['success']) {
                    $txCount = count($camtResult['transactions'] ?? []);
                    $this->logger->info('CAMT successful', ['transactions' => $txCount]);
                    
                    if ($txCount > 0) {
                        return $camtResult;
                    }
                }
                if (isset($camtResult['needs_tan']) && $camtResult['needs_tan']) {
                    return $camtResult;
                }
                
                if (!($camtResult['success'] ?? false)) {
                    $errors['CAMT'] = $camtResult['message'] ?? 'Unbekannter Fehler';
                }
            } catch (\Throwable $e) {
                $errors['CAMT'] = $e->getMessage();
                $this->logger->warning('CAMT failed', ['error' => $e->getMessage()]);
            }
        }

        // Return MT940 result even with 0 transactions
        if ($mt940Result !== null && ($mt940Result['success'] ?? false)) {
            return $mt940Result;
        }
        
        if ($camtResult !== null && ($camtResult['success'] ?? false)) {
            return $camtResult;
        }

        if (empty($errors)) {
            return [
                'success' => false,
                'message' => 'Diese Bank unterstützt keinen Transaktionsabruf über FinTS.'
            ];
        }

        $errorMsg = 'Transaktionsabruf fehlgeschlagen.';
        foreach ($errors as $format => $error) {
            $errorMsg .= " $format: $error";
        }
        
        return [
            'success' => false,
            'message' => $errorMsg
        ];
    }

    /**
     * Get transactions for an account
     */
    public function getTransactions(array $bankConfig, SEPAAccount $account, ?\DateTime $from = null, ?\DateTime $to = null, ?string $persistedInstance = null): array
    {
        try {
            $options = $this->createOptions($bankConfig);
            $credentials = $this->createCredentials($bankConfig);
            
            if ($persistedInstance) {
                $this->finTs = FinTs::new($options, $credentials, $persistedInstance);
            } else {
                $this->finTs = FinTs::new($options, $credentials);
                $this->selectTanMode();
            }

            // Login first
            $login = $this->finTs->login();
            if ($login->needsTan()) {
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($login),
                    'persisted_action' => base64_encode(serialize($login)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }

            // Default to last 30 days if no date range specified
            if ($from === null) {
                $from = new \DateTime('-30 days');
            }
            if ($to === null) {
                $to = new \DateTime();
            }

            $this->logger->info('Fetching transactions', [
                'account' => $account->getIban(),
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d')
            ]);

            $getStatement = GetStatementOfAccount::create($account, $from, $to);
            $this->finTs->execute($getStatement);

            if ($getStatement->needsTan()) {
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($getStatement),
                    'persisted_action' => base64_encode(serialize($getStatement)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }

            return $this->processTransactionsResult($getStatement);

        } catch (Exception $e) {
            $this->logger->error('Failed to get transactions', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Fehler beim Abrufen der Transaktionen: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Ensure UPD allows HKCAZ (CAMT) for the given account.
     * 
     * Some banks (e.g. MLP) support CAMT in BPD (HICAZS) but don't list HKCAZ
     * in the per-account UPD (HIUPD). The phpFinTS library checks UPD before
     * sending the request, which causes an UnsupportedException.
     * 
     * This method patches the UPD to add HKCAZ permission when BPD confirms
     * the bank supports CAMT.
     */
    private function ensureCAMTSupportInUPD(SEPAAccount $account): void
    {
        try {
            $reflection = new \ReflectionClass($this->finTs);
            $updProperty = $reflection->getProperty('upd');
            $updProperty->setAccessible(true);
            $upd = $updProperty->getValue($this->finTs);

            if ($upd === null) {
                $this->logger->warning('UPD is null, cannot ensure CAMT support');
                return;
            }

            // Already supported — nothing to do
            if ($upd->isRequestSupportedForAccount($account, 'HKCAZ')) {
                return;
            }

            $this->logger->info('HKCAZ not listed in UPD for account, patching UPD to allow CAMT');

            // Create a proper ErlaubteGeschaeftsvorfaelle entry for HKCAZ
            // Must use a concrete class extending BaseDeg, not an anonymous class,
            // because Serializer::serializeDeg() requires BaseDeg instances.
            $fakeGv = new \Fhp\Segment\HIUPD\ErlaubteGeschaeftsvorfaelleV1();
            $fakeGv->geschaeftsvorfall = 'HKCAZ';
            $fakeGv->anzahlBenoetigterSignaturen = 0;

            // Try to find and patch the matching HIUPD entry
            $hiupd = $upd->findHiupd($account);
            if ($hiupd !== null) {
                // HIUPD exists for this account but HKCAZ is not listed
                if (property_exists($hiupd, 'erlaubteGeschaeftsvorfaelle')) {
                    if ($hiupd->erlaubteGeschaeftsvorfaelle === null) {
                        $hiupd->erlaubteGeschaeftsvorfaelle = [];
                    }
                    $hiupd->erlaubteGeschaeftsvorfaelle[] = $fakeGv;
                    $this->logger->info('Added HKCAZ to existing HIUPD entry for account');
                }
            } else {
                // No matching HIUPD found — add a synthetic HIUPD entry
                $fakeHiupd = new class($account, $fakeGv) implements \Fhp\Segment\HIUPD\HIUPD {
                    private SEPAAccount $account;
                    private array $gvList;

                    public function __construct(SEPAAccount $account, \Fhp\Segment\HIUPD\ErlaubteGeschaeftsvorfaelle $gv)
                    {
                        $this->account = $account;
                        $this->gvList = [$gv];
                    }

                    public function matchesAccount(SEPAAccount $other): bool
                    {
                        return $other->getIban() === $this->account->getIban()
                            || $other->getAccountNumber() === $this->account->getAccountNumber();
                    }

                    public function getErlaubteGeschaeftsvorfaelle(): array
                    {
                        return $this->gvList;
                    }
                };

                $upd->hiupd[] = $fakeHiupd;
                $this->logger->info('Added synthetic HIUPD entry with HKCAZ for account');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to ensure CAMT support in UPD', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Fetch transactions using CAMT XML format
     */
    private function fetchTransactionsXML(SEPAAccount $account, \DateTime $from, \DateTime $to): array
    {
        try {
            // Debug: Log UPD information to understand why HKCAZ might be rejected
            $this->debugLogUPD($account);

            // Ensure UPD allows HKCAZ for this account (some banks don't list it
            // in per-account HIUPD even though BPD supports CAMT)
            $this->ensureCAMTSupportInUPD($account);
            
            $this->logger->info('Creating CAMT XML statement request');
            $getStatement = GetStatementOfAccountXML::create($account, $from, $to);
            
            $this->logger->info('Executing CAMT XML request');
            $this->finTs->execute($getStatement);

            if ($getStatement->needsTan()) {
                $this->logger->info('GetStatementOfAccountXML requires TAN');
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($getStatement),
                    'persisted_action' => base64_encode(serialize($getStatement)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }

            $this->logger->info('CAMT XML request successful, processing result');
            return $this->processTransactionsXMLResult($getStatement);

        } catch (\Throwable $e) {
            $this->logger->error('CAMT XML fetch failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e)
            ]);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Debug: Log UPD information to understand HKCAZ support
     */
    private function debugLogUPD(SEPAAccount $account): void
    {
        try {
            // Access UPD via reflection since it's private
            $reflection = new \ReflectionClass($this->finTs);
            $updProperty = $reflection->getProperty('upd');
            $updProperty->setAccessible(true);
            $upd = $updProperty->getValue($this->finTs);
            
            if ($upd === null) {
                $this->logger->warning('UPD DEBUG: UPD is NULL - this will cause CAMT to fail');
                return;
            }
            
            $this->logger->info('UPD DEBUG: UPD loaded', [
                'upd_version' => $upd->getVersion(),
                'hiupd_count' => count($upd->hiupd ?? [])
            ]);
            
            // Log all HIUPD entries
            foreach ($upd->hiupd as $idx => $hiupd) {
                $accountNumber = null;
                $iban = null;
                
                // Get account identifiers from HIUPD
                if (property_exists($hiupd, 'kontoverbindung') && $hiupd->kontoverbindung) {
                    $accountNumber = $hiupd->kontoverbindung->kontonummer ?? null;
                }
                if (property_exists($hiupd, 'iban')) {
                    $iban = $hiupd->iban ?? null;
                }
                
                // Get allowed operations
                $allowedOps = [];
                foreach ($hiupd->getErlaubteGeschaeftsvorfaelle() as $gv) {
                    $allowedOps[] = $gv->getGeschaeftsvorfall();
                }
                
                $this->logger->info('UPD DEBUG: HIUPD entry', [
                    'index' => $idx,
                    'account_number' => $accountNumber,
                    'iban' => $iban,
                    'allowed_ops_count' => count($allowedOps),
                    'allowed_ops' => implode(', ', array_slice($allowedOps, 0, 20)), // First 20
                    'has_HKCAZ' => in_array('HKCAZ', $allowedOps),
                    'has_HKKAZ' => in_array('HKKAZ', $allowedOps) // MT940
                ]);
            }
            
            // Check if this specific account is found
            $this->logger->info('UPD DEBUG: Looking for account', [
                'search_iban' => $account->getIban(),
                'search_account_number' => $account->getAccountNumber()
            ]);
            
            // Try to find matching HIUPD
            $found = false;
            foreach ($upd->hiupd as $hiupd) {
                if ($hiupd->matchesAccount($account)) {
                    $found = true;
                    $allowedOps = [];
                    foreach ($hiupd->getErlaubteGeschaeftsvorfaelle() as $gv) {
                        $allowedOps[] = $gv->getGeschaeftsvorfall();
                    }
                    $this->logger->info('UPD DEBUG: FOUND matching HIUPD for account', [
                        'has_HKCAZ' => in_array('HKCAZ', $allowedOps),
                        'has_HKKAZ' => in_array('HKKAZ', $allowedOps),
                        'all_ops' => implode(', ', $allowedOps)
                    ]);
                    break;
                }
            }
            
            if (!$found) {
                $this->logger->warning('UPD DEBUG: NO matching HIUPD found for account!');
            }
            
        } catch (\Throwable $e) {
            $this->logger->warning('UPD DEBUG: Could not access UPD', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Process CAMT XML transactions result
     * 
     * Uses GetStatementOfAccountXML::getBookedXML() which returns string[]
     * See: https://github.com/nemiah/phpFinTS/blob/master/lib/Fhp/Action/GetStatementOfAccountXML.php
     */
    private function processTransactionsXMLResult(GetStatementOfAccountXML $action): array
    {
        $transactions = [];
        $balance = null;
        $balanceDate = null;

        // GetStatementOfAccountXML::getBookedXML() returns string[] (array of XML documents)
        $xmlStatements = $action->getBookedXML();
        
        $this->logger->info('Got CAMT XML statements', ['count' => count($xmlStatements)]);

        if (empty($xmlStatements)) {
            $this->logger->info('No XML statements returned from bank');
            return [
                'success' => true,
                'transactions' => [],
                'balance' => null,
                'balance_date' => null,
                'persisted_instance' => $this->persistAfterClose()
            ];
        }
        
        foreach ($xmlStatements as $index => $xmlContent) {
            // Log first 500 chars of XML for debugging
            $this->logger->debug('CAMT XML content sample', [
                'index' => $index,
                'length' => strlen($xmlContent),
                'sample' => substr($xmlContent, 0, 500)
            ]);
            
            // Parse CAMT XML
            try {
                $xml = new \SimpleXMLElement($xmlContent);
                
                // Detect the namespace from the document
                $namespaces = $xml->getNamespaces(true);
                $this->logger->info('CAMT XML namespaces detected', ['namespaces' => $namespaces]);
                
                // Register all common CAMT namespaces
                $camtNamespaces = [
                    'camt052v02' => 'urn:iso:std:iso:20022:tech:xsd:camt.052.001.02',
                    'camt052v08' => 'urn:iso:std:iso:20022:tech:xsd:camt.052.001.08',
                    'camt053v02' => 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.02',
                    'camt053v08' => 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.08',
                    'camt054v02' => 'urn:iso:std:iso:20022:tech:xsd:camt.054.001.02',
                    'camt054v08' => 'urn:iso:std:iso:20022:tech:xsd:camt.054.001.08',
                ];
                
                foreach ($camtNamespaces as $prefix => $uri) {
                    $xml->registerXPathNamespace($prefix, $uri);
                }
                
                // Also register the default namespace if present
                $defaultNs = $namespaces[''] ?? null;
                if ($defaultNs) {
                    $xml->registerXPathNamespace('ns', $defaultNs);
                    $this->logger->info('Using default namespace', ['ns' => $defaultNs]);
                }
                
                // Try multiple XPath expressions to find entries
                $entries = [];
                $xpathExpressions = [
                    '//ns:Ntry',           // Default namespace
                    '//Ntry',              // No namespace
                    '//camt052v02:Ntry',
                    '//camt052v08:Ntry',
                    '//camt053v02:Ntry',
                    '//camt053v08:Ntry',
                    '//camt054v02:Ntry',
                    '//camt054v08:Ntry',
                ];
                
                foreach ($xpathExpressions as $xpath) {
                    $result = $xml->xpath($xpath);
                    if ($result && count($result) > 0) {
                        $entries = $result;
                        $this->logger->info('Found entries with XPath', [
                            'xpath' => $xpath,
                            'count' => count($entries)
                        ]);
                        break;
                    }
                }
                
                if (empty($entries)) {
                    $this->logger->warning('No Ntry elements found in CAMT XML', [
                        'tried_xpaths' => $xpathExpressions
                    ]);
                    
                    // Try to find what elements ARE present
                    $children = $xml->children();
                    $childNames = [];
                    foreach ($children as $child) {
                        $childNames[] = $child->getName();
                    }
                    $this->logger->debug('Root element children', ['children' => $childNames]);
                }
                
                foreach ($entries as $entry) {
                    $amount = (float) ($entry->Amt ?? 0);
                    $creditDebit = (string) ($entry->CdtDbtInd ?? '');
                    if ($creditDebit === 'DBIT') {
                        $amount = -$amount;
                    }

                    $bookingDate = (string) ($entry->BookgDt->Dt ?? $entry->BookgDt->DtTm ?? null);
                    $valutaDate = (string) ($entry->ValDt->Dt ?? $entry->ValDt->DtTm ?? null);
                    
                    // Try multiple paths for party names
                    $name = '';
                    $namePaths = [
                        $entry->NtryDtls->TxDtls->RltdPties->Cdtr->Nm ?? null,
                        $entry->NtryDtls->TxDtls->RltdPties->Dbtr->Nm ?? null,
                        $entry->NtryDtls->TxDtls->RltdPties->CdtrAcct->Nm ?? null,
                        $entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Nm ?? null,
                    ];
                    foreach ($namePaths as $path) {
                        if ($path !== null && (string)$path !== '') {
                            $name = (string)$path;
                            break;
                        }
                    }
                    
                    // Try multiple paths for IBAN
                    $iban = '';
                    $ibanPaths = [
                        $entry->NtryDtls->TxDtls->RltdPties->CdtrAcct->Id->IBAN ?? null,
                        $entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Id->IBAN ?? null,
                    ];
                    foreach ($ibanPaths as $path) {
                        if ($path !== null && (string)$path !== '') {
                            $iban = (string)$path;
                            break;
                        }
                    }
                    
                    // Try multiple paths for description
                    $description = '';
                    $descPaths = [
                        $entry->NtryDtls->TxDtls->RmtInf->Ustrd ?? null,
                        $entry->AddtlNtryInf ?? null,
                        $entry->NtryDtls->TxDtls->AddtlTxInf ?? null,
                    ];
                    foreach ($descPaths as $path) {
                        if ($path !== null && (string)$path !== '') {
                            $description = (string)$path;
                            break;
                        }
                    }
                    
                    $endToEndId = (string) ($entry->NtryDtls->TxDtls->Refs->EndToEndId ?? '');
                    $bookingText = (string) ($entry->AddtlNtryInf ?? '');

                    $transactions[] = [
                        'booking_date' => $bookingDate ? substr($bookingDate, 0, 10) : null,
                        'valuta_date' => $valutaDate ? substr($valutaDate, 0, 10) : null,
                        'amount' => $amount,
                        'currency' => (string) ($entry->Amt['Ccy'] ?? 'EUR'),
                        'name' => $name,
                        'description' => $description,
                        'booking_text' => $bookingText,
                        'iban' => $iban,
                        'bic' => '',
                        'end_to_end_id' => $endToEndId,
                        'mandate_id' => (string) ($entry->NtryDtls->TxDtls->Refs->MndtId ?? ''),
                        'creditor_id' => '',
                        'prima_nota' => '',
                    ];
                }

                // Try to get balance
                $balXpaths = [
                    '//ns:Bal[ns:Tp/ns:CdOrPrtry/ns:Cd="CLBD"]',
                    '//Bal[Tp/CdOrPrtry/Cd="CLBD"]',
                    '//ns:Bal[ns:Tp/ns:CdOrPrtry/ns:Cd="ITBD"]',
                    '//Bal[Tp/CdOrPrtry/Cd="ITBD"]',
                ];
                
                foreach ($balXpaths as $xpath) {
                    $balNode = $xml->xpath($xpath);
                    if ($balNode && count($balNode) > 0) {
                        $balance = (float) ($balNode[0]->Amt ?? 0);
                        if ((string) ($balNode[0]->CdtDbtInd ?? '') === 'DBIT') {
                            $balance = -$balance;
                        }
                        $balanceDate = (string) ($balNode[0]->Dt->Dt ?? date('Y-m-d'));
                        $this->logger->info('Found balance', ['balance' => $balance, 'xpath' => $xpath]);
                        break;
                    }
                }

            } catch (\Throwable $e) {
                $this->logger->error('Failed to parse CAMT XML', [
                    'error' => $e->getMessage(),
                    'xml_sample' => substr($xmlContent, 0, 200)
                ]);
            }
        }

        $this->logger->info('Processed CAMT transactions', ['count' => count($transactions)]);

        return [
            'success' => true,
            'transactions' => $transactions,
            'balance' => $balance,
            'balance_date' => $balanceDate,
            'persisted_instance' => $this->finTs->persist()
        ];
    }

    /**
     * Process transactions result from GetStatementOfAccount (MT940)
     */
    private function processTransactionsResult(GetStatementOfAccount $action): array
    {
        $this->logger->info('=== processTransactionsResult START ===');
        
        $soa = $action->getStatement();
        $transactions = [];
        $balance = null;
        $balanceDate = null;

        $statements = $soa->getStatements();
        $this->logger->info('MT940: Got statements', ['count' => count($statements)]);
        
        // Also log raw MT940 for debugging
        $rawMT940 = $action->getRawMT940();
        $rawLength = strlen($rawMT940);
        $this->logger->info('MT940 raw data info', [
            'length' => $rawLength,
            'is_empty' => $rawLength === 0,
            'sample' => substr($rawMT940, 0, 500)
        ]);
        
        // If raw MT940 is empty, log this explicitly
        if ($rawLength === 0) {
            $this->logger->warning('MT940: Bank returned empty response - no transaction data');
        }

        foreach ($statements as $stmtIndex => $statement) {
            // Get the end balance from the most recent statement
            $statementBalance = $statement->getEndBalance();
            $stmtDate = $statement->getDate();
            
            $this->logger->info('MT940: Processing statement', [
                'index' => $stmtIndex,
                'date' => $stmtDate?->format('Y-m-d'),
                'start_balance' => $statement->getStartBalance(),
                'end_balance' => $statementBalance,
                'transaction_count' => count($statement->getTransactions())
            ]);
            
            if ($statementBalance !== null) {
                $balance = $statementBalance;
                $balanceDate = $stmtDate?->format('Y-m-d H:i:s');
            }

            foreach ($statement->getTransactions() as $txIndex => $tx) {
                $amount = $tx->getAmount();
                // Transaction::CD_DEBIT = 'debit'
                if ($tx->getCreditDebit() === 'debit') {
                    $amount = -$amount;
                }

                $structuredDesc = $tx->getStructuredDescription();
                
                // Log first few transactions for debugging
                if ($txIndex < 3) {
                    $this->logger->debug('MT940: Transaction sample', [
                        'index' => $txIndex,
                        'amount' => $amount,
                        'name' => $tx->getName(),
                        'booking_date' => $tx->getBookingDate()?->format('Y-m-d'),
                        'description' => substr($tx->getMainDescription(), 0, 100)
                    ]);
                }
                
                $transactions[] = [
                    'booking_date' => $tx->getBookingDate()?->format('Y-m-d'),
                    'valuta_date' => $tx->getValutaDate()?->format('Y-m-d'),
                    'amount' => $amount,
                    'currency' => 'EUR',
                    'name' => $tx->getName(),
                    'description' => $tx->getMainDescription(),
                    'booking_text' => $tx->getBookingText(),
                    'iban' => $structuredDesc['IBAN'] ?? null,
                    'bic' => $structuredDesc['BIC'] ?? null,
                    'end_to_end_id' => $tx->getEndToEndID(),
                    'mandate_id' => $structuredDesc['MREF'] ?? null,
                    'creditor_id' => $structuredDesc['CRED'] ?? null,
                    'prima_nota' => (string) $tx->getPN(),
                ];
            }
        }

        $this->logger->info('MT940: Processed transactions', ['total_count' => count($transactions)]);

        return [
            'success' => true,
            'transactions' => $transactions,
            'balance' => $balance,
            'balance_date' => $balanceDate,
            'persisted_instance' => $this->finTs->persist()
        ];
    }

    /**
     * Find SEPAAccount by IBAN in a list
     */
    public function findAccountByIban(array $accounts, string $iban): ?SEPAAccount
    {
        foreach ($accounts as $account) {
            if ($account->getIban() === $iban) {
                return $account;
            }
        }
        return null;
    }

    /**
     * Ensure a transaction result includes correct balance data.
     * Always attempts to fetch the balance via GetBalance (HISAL) which provides
     * correctly signed balances. Falls back to the balance from MT940/CAMT if
     * the HISAL fetch fails.
     *
     * @param array &$result The transaction result array to augment with balance data
     * @param SEPAAccount $sepaAccount The SEPA account to fetch the balance for
     * @return void
     */
    private function ensureBalanceInResult(array &$result, SEPAAccount $sepaAccount): void
    {
        try {
            $balance = $this->getAccountBalance($sepaAccount);
            if ($balance) {
                $this->logger->debug('Using HISAL balance (correctly signed)', [
                    'amount' => $balance['amount'],
                    'previous_balance' => $result['balance'] ?? null
                ]);
                $result['balance'] = $balance['amount'];
                $result['balance_date'] = $balance['date'];
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('HISAL balance fetch failed, keeping existing balance', [
                'error' => $e->getMessage(),
                'existing_balance' => $result['balance'] ?? null
            ]);
        }

        if (!isset($result['balance'])) {
            $this->logger->debug('No balance available from any source');
        }
    }

    /**
     * Sync account transactions - combined method that handles everything in one session
     * 
     * @param array $bankConfig Bank configuration
     * @param string $accountIdentifier IBAN or account number
     * @param \DateTime $from Start date
     * @param \DateTime $to End date
     * @param string|null $persistedInstance Persisted FinTS session (preserves kundensystemId for TAN-free access)
     */
    public function syncAccountTransactions(array $bankConfig, string $accountIdentifier, \DateTime $from, \DateTime $to, ?string $persistedInstance = null): array
    {
        $this->logger->info('=== syncAccountTransactions START ===', [
            'bank_code' => $bankConfig['bank_code'] ?? 'unknown',
            'account' => $accountIdentifier,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'has_persisted_instance' => $persistedInstance !== null
        ]);
        
        try {
            $options = $this->createOptions($bankConfig);
            $credentials = $this->createCredentials($bankConfig);
            
            // Use persisted instance if available (preserves kundensystemId for PSD2 90-day TAN-free access)
            if ($persistedInstance !== null) {
                $this->logger->info('Restoring persisted FinTS instance for transaction sync');
                $this->finTs = FinTs::new($options, $credentials, $persistedInstance);
            } else {
                $this->logger->info('Creating new FinTS instance for transaction sync');
                $this->finTs = FinTs::new($options, $credentials);
                $this->selectTanMode();
            }

            // Login
            $login = $this->finTs->login();
            if ($login->needsTan()) {
                $this->logger->info('Login requires TAN for sync');
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($login),
                    'persisted_action' => base64_encode(serialize($login)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }

            // Get SEPA accounts
            $getSepaAccounts = GetSEPAAccounts::create();
            $this->finTs->execute($getSepaAccounts);

            if ($getSepaAccounts->needsTan()) {
                $this->logger->info('GetSEPAAccounts requires TAN');
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($getSepaAccounts),
                    'persisted_action' => base64_encode(serialize($getSepaAccounts)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }

            // Find the matching account
            $sepaAccount = null;
            foreach ($getSepaAccounts->getAccounts() as $acc) {
                if ($acc->getIban() === $accountIdentifier || $acc->getAccountNumber() === $accountIdentifier) {
                    $sepaAccount = $acc;
                    break;
                }
            }

            if (!$sepaAccount) {
                return [
                    'success' => false,
                    'message' => 'Konto nicht gefunden: ' . $accountIdentifier
                ];
            }

            $this->logger->info('Fetching transactions', [
                'account' => $sepaAccount->getIban(),
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d')
            ]);

            // Determine which format to try based on what the bank supports
            // Check BPD for supported formats
            $supportsMT940 = false;
            $supportsCAMT = false;
            
            try {
                $bpd = $this->finTs->getBpd();
                if ($bpd) {
                    $mt940Params = $bpd->parameters['HIKAZS'] ?? [];
                    $camtParams = $bpd->parameters['HICAZS'] ?? [];
                    
                    // Check if any MT940 version is supported by phpFinTS (4,5,6,7)
                    foreach (array_keys($mt940Params) as $version) {
                        if (in_array((int)$version, [4, 5, 6, 7])) {
                            $supportsMT940 = true;
                            break;
                        }
                    }
                    
                    // Check if CAMT version 1 is supported
                    foreach (array_keys($camtParams) as $version) {
                        if ((int)$version === 1) {
                            $supportsCAMT = true;
                            break;
                        }
                    }
                    
                    $this->logger->info('Format support detected', [
                        'mt940' => $supportsMT940,
                        'camt' => $supportsCAMT,
                        'mt940_versions' => array_keys($mt940Params),
                        'camt_versions' => array_keys($camtParams)
                    ]);
                }
            } catch (Exception $e) {
                $this->logger->warning('Could not check BPD for format support', ['error' => $e->getMessage()]);
                // Assume both are possible, will try MT940 first
                $supportsMT940 = true;
                $supportsCAMT = true;
            }

            $errors = [];
            
            // Try MT940 first if supported
            $mt940Result = null;
            if ($supportsMT940) {
                $this->logger->info('Trying MT940 format');
                try {
                    $getStatement = GetStatementOfAccount::create($sepaAccount, $from, $to);
                    $this->finTs->execute($getStatement);

                    if ($getStatement->needsTan()) {
                        $this->logger->info('GetStatementOfAccount requires TAN');
                        return [
                            'success' => false,
                            'needs_tan' => true,
                            'tan_request' => $this->extractTanRequest($getStatement),
                            'persisted_action' => base64_encode(serialize($getStatement)),
                            'persisted_instance' => $this->finTs->persist()
                        ];
                    }

                    $mt940Result = $this->processTransactionsResult($getStatement);
                    if ($mt940Result['success']) {
                        $txCount = count($mt940Result['transactions'] ?? []);
                        $this->logger->info('MT940 fetch successful', ['transactions' => $txCount]);
                        
                        // If we got transactions, return them
                        if ($txCount > 0) {
                            $this->ensureBalanceInResult($mt940Result, $sepaAccount);
                            $mt940Result['persisted_instance'] = $this->persistAfterClose();
                            return $mt940Result;
                        }
                        // If 0 transactions, try CAMT before returning (might have better data)
                        $this->logger->info('MT940 returned 0 transactions, will try CAMT as well');
                    } else {
                        $errors['MT940'] = $mt940Result['message'] ?? 'Unbekannter Fehler';
                    }
                } catch (\Throwable $e) {
                    $errors['MT940'] = $e->getMessage();
                    $this->logger->info('MT940 fetch failed', ['error' => $e->getMessage()]);
                }
            }

            // Try CAMT XML if supported (and MT940 didn't work, had errors, or returned 0 transactions)
            $camtResult = null;
            if ($supportsCAMT) {
                $this->logger->info('Trying CAMT XML format');
                try {
                    $camtResult = $this->fetchTransactionsXML($sepaAccount, $from, $to);
                    
                    if (isset($camtResult['success']) && $camtResult['success']) {
                        $txCount = count($camtResult['transactions'] ?? []);
                        $this->logger->info('CAMT XML fetch successful', ['transactions' => $txCount]);
                        
                        // If CAMT has transactions, return it
                        if ($txCount > 0) {
                            $this->ensureBalanceInResult($camtResult, $sepaAccount);
                            $camtResult['persisted_instance'] = $this->persistAfterClose();
                            return $camtResult;
                        }
                    }
                    if (isset($camtResult['needs_tan']) && $camtResult['needs_tan']) {
                        return $camtResult;
                    }
                    
                    if (!($camtResult['success'] ?? false)) {
                        $errors['CAMT'] = $camtResult['message'] ?? 'Unbekannter Fehler';
                    }
                } catch (\Throwable $e) {
                    $errors['CAMT'] = $e->getMessage();
                    $this->logger->warning('CAMT XML fetch failed', ['error' => $e->getMessage()]);
                }
            }

            // If MT940 was successful (even with 0 transactions), return that result
            // This ensures we at least get balance info, etc.
            if ($mt940Result !== null && ($mt940Result['success'] ?? false)) {
                $this->ensureBalanceInResult($mt940Result, $sepaAccount);
                $this->logger->info('Returning MT940 result (may have 0 transactions)', [
                    'transactions' => count($mt940Result['transactions'] ?? []),
                    'balance' => $mt940Result['balance'] ?? null
                ]);
                // Add persisted instance for session reuse (close dialog first)
                $mt940Result['persisted_instance'] = $this->persistAfterClose();
                return $mt940Result;
            }
            
            // Same for CAMT
            if ($camtResult !== null && ($camtResult['success'] ?? false)) {
                $this->ensureBalanceInResult($camtResult, $sepaAccount);
                $this->logger->info('Returning CAMT result (may have 0 transactions)', [
                    'transactions' => count($camtResult['transactions'] ?? [])
                ]);
                // Add persisted instance for session reuse (close dialog first)
                $camtResult['persisted_instance'] = $this->persistAfterClose();
                return $camtResult;
            }

            // Both formats failed or neither was available
            if (empty($errors)) {
                return [
                    'success' => false,
                    'message' => 'Diese Bank unterstützt keinen Transaktionsabruf über FinTS (weder MT940 noch CAMT XML).'
                ];
            }

            $errorMsg = 'Transaktionsabruf fehlgeschlagen.';
            foreach ($errors as $format => $error) {
                // Simplify common error messages
                if (strpos($error, 'HIKAZS') !== false || strpos($error, 'HICAZS') !== false) {
                    $errorMsg .= " $format: Format nicht verfügbar.";
                } else {
                    $errorMsg .= " $format: $error";
                }
            }
            
            return [
                'success' => false,
                'message' => $errorMsg
            ];

        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $this->logger->error('Sync failed', ['error' => $errorMessage, 'trace' => $e->getTraceAsString()]);
            
            // If session-related error and we had a persisted instance, retry with fresh session
            if ($persistedInstance !== null && (
                strpos($errorMessage, 'Dialogkontext') !== false ||
                strpos($errorMessage, 'Dialog') !== false ||
                strpos($errorMessage, 'session') !== false ||
                strpos($errorMessage, 'Need to login') !== false
            )) {
                $this->logger->info('Session appears expired, retrying with fresh connection');
                return $this->syncAccountTransactions($bankConfig, $accountIdentifier, $from, $to, null);
            }
            
            return [
                'success' => false,
                'message' => 'Fehler: ' . $errorMessage
            ];
        }
    }

    /**
     * Get depot holdings (securities/Wertpapierbestand)
     */
    public function getDepotHoldings(array $bankConfig, string $accountIdentifier, ?string $persistedInstance = null): array
    {
        $this->logger->info('=== getDepotHoldings START ===', ['account' => $accountIdentifier]);
        
        try {
            $options = $this->createOptions($bankConfig);
            $credentials = $this->createCredentials($bankConfig);
            
            if ($persistedInstance) {
                $this->finTs = FinTs::new($options, $credentials, $persistedInstance);
            } else {
                $this->finTs = FinTs::new($options, $credentials);
                $this->selectTanMode();
            }
            
            // Login
            $login = $this->finTs->login();
            if ($login->needsTan()) {
                $this->logger->info('Login requires TAN for depot fetch');
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($login),
                    'persisted_action' => base64_encode(serialize($login)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }
            
            // Get SEPA accounts to find the depot
            $getSepaAccounts = GetSEPAAccounts::create();
            $this->finTs->execute($getSepaAccounts);
            
            if ($getSepaAccounts->needsTan()) {
                return [
                    'success' => false,
                    'needs_tan' => true,
                    'tan_request' => $this->extractTanRequest($getSepaAccounts),
                    'persisted_action' => base64_encode(serialize($getSepaAccounts)),
                    'persisted_instance' => $this->finTs->persist()
                ];
            }
            
            // Find the matching depot account
            $depotAccount = null;
            foreach ($getSepaAccounts->getAccounts() as $acc) {
                $accNum = $acc->getAccountNumber();
                $subAcc = method_exists($acc, 'getSubAccount') ? $acc->getSubAccount() : null;
                
                // Match by account number or sub-account
                if ($accNum === $accountIdentifier || $subAcc === $accountIdentifier) {
                    $depotAccount = $acc;
                    break;
                }
            }
            
            if (!$depotAccount) {
                return [
                    'success' => false,
                    'message' => 'Depot nicht gefunden: ' . $accountIdentifier
                ];
            }
            
            $this->logger->info('Found depot, fetching holdings', [
                'account_number' => $depotAccount->getAccountNumber()
            ]);
            
            // Check if GetDepotAufstellung is available
            if (!class_exists(GetDepotAufstellung::class)) {
                return [
                    'success' => false,
                    'message' => 'Depot-Abfrage wird von dieser phpFinTS-Version nicht unterstützt'
                ];
            }
            
            // Fetch depot holdings
            try {
                $getDepot = GetDepotAufstellung::create($depotAccount);
                $this->finTs->execute($getDepot);
                
                if ($getDepot->needsTan()) {
                    $this->logger->info('GetDepotAufstellung requires TAN');
                    return [
                        'success' => false,
                        'needs_tan' => true,
                        'tan_request' => $this->extractTanRequest($getDepot),
                        'persisted_action' => base64_encode(serialize($getDepot)),
                        'persisted_instance' => $this->finTs->persist()
                    ];
                }
                
                $holdings = $this->processDepotResult($getDepot);
                
                return [
                    'success' => true,
                    'holdings' => $holdings,
                    'persisted_instance' => $this->persistAfterClose()
                ];
                
            } catch (\Throwable $e) {
                $this->logger->error('Depot fetch failed', ['error' => $e->getMessage()]);
                return [
                    'success' => false,
                    'message' => 'Depot-Abfrage fehlgeschlagen: ' . $e->getMessage()
                ];
            }
            
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $this->logger->error('getDepotHoldings failed', [
                'error' => $errorMessage,
                'exception_class' => get_class($e),
                'previous' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
                'trace' => $e->getTraceAsString()
            ]);
            
            // If session-related error and we had a persisted instance, retry with fresh session
            if ($persistedInstance !== null && (
                strpos($errorMessage, 'Dialogkontext') !== false ||
                strpos($errorMessage, 'Dialog') !== false ||
                strpos($errorMessage, 'session') !== false ||
                strpos($errorMessage, 'Need to login') !== false
            )) {
                $this->logger->info('Session appears expired, retrying with fresh connection');
                return $this->getDepotHoldings($bankConfig, $accountIdentifier, null);
            }
            
            return [
                'success' => false,
                'message' => 'Fehler: ' . $errorMessage
            ];
        }
    }
    
    /**
     * Process depot holdings result
     * Based on phpFinTS GetDepotAufstellung which returns StatementOfHoldings
     */
    private function processDepotResult(GetDepotAufstellung $action): array
    {
        $holdings = [];
        
        try {
            // Log raw MT535 data for debugging
            $rawMT535 = $action->getRawMT535();
            $this->logger->info('Raw MT535 data from bank', [
                'length' => strlen($rawMT535),
                'data' => $rawMT535  // Log full data for debugging
            ]);
            
            // Get the StatementOfHoldings from the action
            $statement = $action->getStatement();
            
            // Get total depot value
            $depotWert = $action->getDepotWert();
            $this->logger->info('Depot total value from bank', ['depot_wert' => $depotWert]);
            
            // Get holdings from StatementOfHoldings
            $positions = $statement->getHoldings();
            
            $this->logger->info('Got holdings from statement', ['count' => count($positions)]);
            
            foreach ($positions as $position) {
                // Holding class methods: getISIN(), getWKN(), getName(), getAmount(), 
                // getPrice(), getAcquisitionPrice(), getValue(), getCurrency(), getDate()
                
                $isin = $position->getISIN();
                $wkn = $position->getWKN();
                $name = $position->getName() ?? 'Unbekanntes Wertpapier';
                $quantity = $position->getAmount() ?? 0;
                $currentPrice = $position->getPrice();
                $totalValue = $position->getValue();
                $currency = $position->getCurrency() ?? 'EUR';
                $priceDate = $position->getDate();
                
                // The value from :70E::HOLD// is the TOTAL acquisition value, not price per unit
                // We need to calculate the average acquisition price per unit
                $acquisitionTotalValue = $position->getAcquisitionPrice(); // This is actually total value
                $purchasePrice = null;
                
                if ($acquisitionTotalValue !== null && $quantity > 0) {
                    // Calculate average purchase price per unit
                    $purchasePrice = $acquisitionTotalValue / $quantity;
                }
                
                // Calculate profit/loss based on total values
                $profitLoss = null;
                $profitLossPercent = null;
                if ($acquisitionTotalValue !== null && $totalValue !== null && $acquisitionTotalValue > 0) {
                    // Profit/Loss = Current total value - Acquisition total value
                    $profitLoss = $totalValue - $acquisitionTotalValue;
                    $profitLossPercent = (($totalValue / $acquisitionTotalValue) - 1) * 100;
                }
                
                $holding = [
                    'isin' => $isin,
                    'wkn' => $wkn,
                    'name' => $name,
                    'quantity' => $quantity,
                    'currency' => $currency,
                    'current_price' => $currentPrice,
                    'purchase_price' => $purchasePrice,
                    'total_value' => $totalValue,
                    'profit_loss' => $profitLoss,
                    'profit_loss_percent' => $profitLossPercent,
                    'price_date' => $priceDate instanceof \DateTime ? $priceDate->format('Y-m-d H:i:s') : null
                ];
                
                $this->logger->info('Processed holding', [
                    'isin' => $isin,
                    'wkn' => $wkn,
                    'name' => $name,
                    'quantity' => $quantity,
                    'currency' => $currency,
                    'current_price' => $currentPrice,
                    'acquisition_total' => $acquisitionTotalValue,
                    'avg_purchase_price' => $purchasePrice,
                    'total_value' => $totalValue,
                    'profit_loss' => $profitLoss,
                    'profit_loss_percent' => $profitLossPercent
                ]);
                
                $holdings[] = $holding;
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('Error processing depot result', ['error' => $e->getMessage()]);
        }
        
        $this->logger->info('Processed depot holdings', ['count' => count($holdings)]);
        return $holdings;
    }

    /**
     * Get TAN modes
     */
    public function getTanModes(array $bankConfig): array
    {
        try {
            $this->init($bankConfig);
            $tanModes = $this->finTs->getTanModes();
            
            return array_map(function($mode) {
                return [
                    'id' => $mode->getId(),
                    'name' => $mode->getName(),
                ];
            }, $tanModes);
        } catch (Exception $e) {
            $this->logger->error('Failed to get TAN modes', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Close FinTS session
     */
    public function close(): void
    {
        if ($this->finTs) {
            try {
                $this->finTs->close();
            } catch (Exception $e) {
                $this->logger->debug('Error closing FinTS session', ['error' => $e->getMessage()]);
            }
            $this->finTs = null;
        }
    }

    /**
     * Fetch Bank Parameter Data (BPD) and extract supported capabilities
     * 
     * This queries the bank to find out which FinTS features it supports.
     */
    public function getBankCapabilities(array $bankConfig): array
    {
        try {
            $options = $this->createOptions($bankConfig);
            
            // Fetch BPD without credentials (anonymous)
            $bpd = FinTs::fetchBpd($options);
            
            $capabilities = [
                'bank_name' => $bpd->getBankName(),
                'bpd_version' => $bpd->getVersion(),
                'supports_psd2' => $bpd->supportsPsd2(),
                'tan_modes' => [],
                'all_parameters' => [],
                
                // Read operations
                'read' => [
                    'transactions_mt940' => $this->extractFeature($bpd, 'HIKAZS', [4, 5, 6, 7]),
                    'transactions_camt' => $this->extractFeature($bpd, 'HICAZS', [1]),
                    'balance' => $this->extractFeature($bpd, 'HISALS', [4, 5, 6, 7]),
                    'sepa_accounts' => $this->extractFeature($bpd, 'HISPAS', [1, 2, 3]),
                    'depot' => $this->extractFeature($bpd, 'HIWPDS', [5, 6]),
                ],
                
                // SEPA Transfers
                'transfers' => [
                    'sepa_single' => $this->extractFeature($bpd, 'HICCSS', [1]),
                    'sepa_batch' => $this->extractFeature($bpd, 'HICCMS', [1]),
                    'sepa_scheduled' => $this->extractFeature($bpd, 'HICSES', [1]),
                    'sepa_scheduled_batch' => $this->extractFeature($bpd, 'HICMES', [1]),
                    'instant' => $this->extractFeature($bpd, 'HIIPZS', [1, 2]),
                    'international' => $this->extractFeature($bpd, 'HIAUBS', [9]),
                ],
                
                // SEPA Direct Debits
                'direct_debits' => [
                    'sepa_single' => $this->extractFeature($bpd, 'HIDSES', [1, 2]),
                    'sepa_batch' => $this->extractFeature($bpd, 'HIDMES', [1, 2]),
                    'sepa_b2b_single' => $this->extractFeature($bpd, 'HIBSES', [1, 2]),
                    'sepa_b2b_batch' => $this->extractFeature($bpd, 'HIBMES', [1, 2]),
                ],
            ];

            // Legacy fields for backward compatibility
            $capabilities['mt940_versions'] = $capabilities['read']['transactions_mt940']['bank_versions'];
            $capabilities['camt_versions'] = $capabilities['read']['transactions_camt']['bank_versions'];
            $capabilities['mt940_supported'] = $capabilities['read']['transactions_mt940']['supported'];
            $capabilities['camt_supported'] = $capabilities['read']['transactions_camt']['supported'];
            $capabilities['transactions_supported'] = $capabilities['mt940_supported'] || $capabilities['camt_supported'];
            $capabilities['supports_balance'] = $capabilities['read']['balance']['supported'];
            $capabilities['balance_versions'] = $capabilities['read']['balance']['bank_versions'];
            $capabilities['supports_sepa_accounts'] = $capabilities['read']['sepa_accounts']['supported'];

            // Get all parameter segment names for reference
            foreach ($bpd->parameters as $segmentName => $versions) {
                $capabilities['all_parameters'][$segmentName] = array_keys($versions);
            }

            // Get TAN modes
            foreach ($bpd->allTanModes as $id => $mode) {
                $capabilities['tan_modes'][] = [
                    'id' => $mode->getId(),
                    'name' => $mode->getName(),
                    'is_decoupled' => method_exists($mode, 'isDecoupled') ? $mode->isDecoupled() : false,
                ];
            }

            $this->logger->info('Bank capabilities fetched', [
                'bank' => $capabilities['bank_name'],
                'transactions_mt940' => $capabilities['read']['transactions_mt940'],
                'transactions_camt' => $capabilities['read']['transactions_camt'],
            ]);

            return [
                'success' => true,
                'capabilities' => $capabilities
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to fetch BPD', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Fehler beim Abrufen der Bankparameter: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Extract feature information from BPD
     */
    private function extractFeature($bpd, string $segmentName, array $librarySupports): array
    {
        $params = $bpd->parameters[$segmentName] ?? [];
        $bankVersions = array_keys($params);
        sort($bankVersions);
        
        $supported = false;
        foreach ($bankVersions as $version) {
            if (in_array((int)$version, $librarySupports)) {
                $supported = true;
                break;
            }
        }
        
        return [
            'available' => !empty($bankVersions),
            'supported' => $supported,
            'bank_versions' => $bankVersions,
            'library_versions' => $librarySupports,
        ];
    }

}
