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
     * Get account balance
     */
    private function getAccountBalance(SEPAAccount $account): ?array
    {
        try {
            $getBalance = GetBalance::create($account);
            $this->finTs->execute($getBalance);

            if (!$getBalance->needsTan()) {
                $balances = $getBalance->getBalances();
                if (!empty($balances)) {
                    $balance = reset($balances);
                    
                    // Try different methods to get amount depending on phpFinTS version
                    $amount = null;
                    $date = null;
                    
                    // Try getBooked() first (newer API)
                    if (method_exists($balance, 'getBooked')) {
                        $booked = $balance->getBooked();
                        if ($booked && method_exists($booked, 'getAmount')) {
                            $amountObj = $booked->getAmount();
                            $amount = is_object($amountObj) && method_exists($amountObj, 'toFloat') 
                                ? $amountObj->toFloat() 
                                : (float) $amountObj;
                        }
                    }
                    
                    // Fallback: try direct getAmount()
                    if ($amount === null && method_exists($balance, 'getAmount')) {
                        $amountObj = $balance->getAmount();
                        if (is_object($amountObj) && method_exists($amountObj, 'toFloat')) {
                            $amount = $amountObj->toFloat();
                        } elseif (is_numeric($amountObj)) {
                            $amount = (float) $amountObj;
                        }
                    }
                    
                    // Try to get date
                    if (method_exists($balance, 'getDate')) {
                        $dateObj = $balance->getDate();
                        $date = $dateObj ? $dateObj->format('Y-m-d H:i:s') : null;
                    } elseif (method_exists($balance, 'getValutaDate')) {
                        $dateObj = $balance->getValutaDate();
                        $date = $dateObj ? $dateObj->format('Y-m-d H:i:s') : null;
                    }
                    
                    if ($amount !== null) {
                        return [
                            'amount' => $amount,
                            'date' => $date
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
     * Submit TAN and continue action
     */
    public function submitTan(array $bankConfig, string $persistedInstance, string $persistedAction, string $tan): array
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
            $accountName = $iban ? 'Konto ' . substr($iban, -4) : 'Konto ' . $accountNumber;
            
            $accountData = [
                'account_number' => $accountNumber,
                'iban' => $iban,
                'bic' => $account->getBic(),
                'account_name' => $accountName,
                'owner_name' => null, // Not available in SEPAAccount
                'currency' => 'EUR',
                'balance' => null,
                'balance_date' => null
            ];

            // Try to get balance
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

            $result[] = $accountData;
        }

        return [
            'success' => true,
            'accounts' => $result,
            'persisted_instance' => $this->finTs->persist()
        ];
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
     */
    public function checkDecoupledStatus(array $bankConfig, string $persistedInstance, string $persistedAction): array
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
     * Fetch transactions using CAMT XML format
     */
    private function fetchTransactionsXML(SEPAAccount $account, \DateTime $from, \DateTime $to): array
    {
        try {
            $getStatement = GetStatementOfAccountXML::create($account, $from, $to);
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

            return $this->processTransactionsXMLResult($getStatement);

        } catch (Exception $e) {
            $this->logger->error('CAMT XML fetch failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Transaktionsabruf nicht unterstützt: ' . $e->getMessage()
            ];
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
            $this->logger->info('No XML statements returned');
            return [
                'success' => true,
                'transactions' => [],
                'balance' => null,
                'balance_date' => null,
                'persisted_instance' => $this->finTs->persist()
            ];
        }
        
        foreach ($xmlStatements as $xmlContent) {
            // Parse CAMT XML
            try {
                $xml = new \SimpleXMLElement($xmlContent);
                $xml->registerXPathNamespace('camt', 'urn:iso:std:iso:20022:tech:xsd:camt.052.001.02');
                $xml->registerXPathNamespace('camt2', 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.02');
                
                // Try to find transactions in various CAMT formats
                $entries = $xml->xpath('//camt:Ntry') ?: $xml->xpath('//camt2:Ntry') ?: $xml->xpath('//Ntry') ?: [];
                
                foreach ($entries as $entry) {
                    $amount = (float) ($entry->Amt ?? 0);
                    $creditDebit = (string) ($entry->CdtDbtInd ?? '');
                    if ($creditDebit === 'DBIT') {
                        $amount = -$amount;
                    }

                    $bookingDate = (string) ($entry->BookgDt->Dt ?? $entry->BookgDt->DtTm ?? null);
                    $valutaDate = (string) ($entry->ValDt->Dt ?? $entry->ValDt->DtTm ?? null);
                    
                    $name = (string) ($entry->NtryDtls->TxDtls->RltdPties->Cdtr->Nm ?? 
                                      $entry->NtryDtls->TxDtls->RltdPties->Dbtr->Nm ?? '');
                    
                    $iban = (string) ($entry->NtryDtls->TxDtls->RltdPties->CdtrAcct->Id->IBAN ?? 
                                      $entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Id->IBAN ?? '');
                    
                    $description = (string) ($entry->NtryDtls->TxDtls->RmtInf->Ustrd ?? '');
                    $endToEndId = (string) ($entry->NtryDtls->TxDtls->Refs->EndToEndId ?? '');
                    $bookingText = (string) ($entry->AddtlNtryInf ?? '');

                    // Fallback for description
                    if (empty($description)) {
                        $description = $bookingText;
                    }

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

                // Try to get balance - check for Closing Booked Balance (CLBD) or Interim Booked Balance (ITBD)
                $balNode = $xml->xpath('//camt:Bal[camt:Tp/camt:CdOrPrtry/camt:Cd="CLBD"]') ?: 
                           $xml->xpath('//camt2:Bal[camt2:Tp/camt2:CdOrPrtry/camt2:Cd="CLBD"]') ?:
                           $xml->xpath('//Bal[Tp/CdOrPrtry/Cd="CLBD"]') ?: 
                           $xml->xpath('//camt:Bal[camt:Tp/camt:CdOrPrtry/camt:Cd="ITBD"]') ?:
                           $xml->xpath('//Bal[Tp/CdOrPrtry/Cd="ITBD"]') ?: [];
                if (!empty($balNode)) {
                    $balance = (float) ($balNode[0]->Amt ?? 0);
                    if ((string) ($balNode[0]->CdtDbtInd ?? '') === 'DBIT') {
                        $balance = -$balance;
                    }
                    $balanceDate = (string) ($balNode[0]->Dt->Dt ?? date('Y-m-d'));
                }

            } catch (Exception $e) {
                $this->logger->warning('Failed to parse CAMT XML', ['error' => $e->getMessage()]);
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
     * Process transactions result from GetStatementOfAccount
     */
    private function processTransactionsResult(GetStatementOfAccount $action): array
    {
        $soa = $action->getStatement();
        $transactions = [];
        $balance = null;
        $balanceDate = null;

        foreach ($soa->getStatements() as $statement) {
            // Get the end balance from the most recent statement
            $statementBalance = $statement->getEndBalance();
            if ($statementBalance !== null) {
                $balance = $statementBalance;
                $balanceDate = $statement->getDate()?->format('Y-m-d H:i:s');
            }

            foreach ($statement->getTransactions() as $tx) {
                $amount = $tx->getAmount();
                // Transaction::CD_DEBIT = 'debit'
                if ($tx->getCreditDebit() === 'debit') {
                    $amount = -$amount;
                }

                $structuredDesc = $tx->getStructuredDescription();
                
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

        $this->logger->info('Processed transactions', ['count' => count($transactions)]);

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
     * Sync account transactions - combined method that handles everything in one session
     */
    public function syncAccountTransactions(array $bankConfig, string $accountIdentifier, \DateTime $from, \DateTime $to): array
    {
        try {
            $options = $this->createOptions($bankConfig);
            $credentials = $this->createCredentials($bankConfig);
            
            // Always start fresh
            $this->finTs = FinTs::new($options, $credentials);
            $this->selectTanMode();

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

            // Try MT940 format first (GetStatementOfAccount), then CAMT XML format
            $mt940Error = null;
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

                return $this->processTransactionsResult($getStatement);

            } catch (Exception $e) {
                $mt940Error = $e->getMessage();
                $this->logger->info('MT940 fetch failed, will try CAMT XML', ['error' => $mt940Error]);
            }

            // If MT940 failed, try CAMT XML format
            if ($mt940Error !== null) {
                $this->logger->info('Trying CAMT XML format as fallback');
                
                $camtError = null;
                try {
                    $result = $this->fetchTransactionsXML($sepaAccount, $from, $to);
                    
                    // Return if successful or needs TAN
                    if ($result['success']) {
                        return $result;
                    }
                    if (isset($result['needs_tan']) && $result['needs_tan']) {
                        return $result;
                    }
                    
                    // CAMT XML returned an error
                    $camtError = $result['message'] ?? 'Unbekannter CAMT-Fehler';
                    
                } catch (Exception $camtEx) {
                    $camtError = $camtEx->getMessage();
                    $this->logger->warning('CAMT XML also failed', ['error' => $camtError]);
                }
                
                // Both formats failed
                $errorMsg = 'Transaktionsabruf fehlgeschlagen. ';
                if (strpos($mt940Error, 'HIKAZS') !== false) {
                    $errorMsg .= 'Diese Bank unterstützt möglicherweise keinen Kontoauszugsabruf per FinTS.';
                } else {
                    $errorMsg .= 'MT940: ' . $mt940Error;
                }
                if ($camtError) {
                    $errorMsg .= ' CAMT: ' . $camtError;
                }
                
                return [
                    'success' => false,
                    'message' => $errorMsg
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Sync failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Fehler: ' . $e->getMessage()
            ];
        }
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
     * This queries the bank to find out which FinTS features it supports,
     * particularly for transaction retrieval (HIKAZS/HICAZS).
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
                'mt940_versions' => [],
                'camt_versions' => [],
                'supports_balance' => false,
                'supports_sepa_accounts' => false,
                'tan_modes' => [],
                'all_parameters' => [],
            ];

            // Check HIKAZS (MT940 transaction retrieval) versions
            $hikazsParams = $bpd->parameters['HIKAZS'] ?? [];
            foreach ($hikazsParams as $version => $segment) {
                $capabilities['mt940_versions'][] = $version;
            }
            sort($capabilities['mt940_versions']);

            // Check HICAZS (CAMT XML transaction retrieval) versions
            $hicazsParams = $bpd->parameters['HICAZS'] ?? [];
            foreach ($hicazsParams as $version => $segment) {
                $capabilities['camt_versions'][] = $version;
            }
            sort($capabilities['camt_versions']);

            // Check balance support (HISALS)
            $hisalsParams = $bpd->parameters['HISALS'] ?? [];
            $capabilities['supports_balance'] = !empty($hisalsParams);
            $capabilities['balance_versions'] = array_keys($hisalsParams);

            // Check SEPA accounts support (HISPAS)
            $hispasParams = $bpd->parameters['HISPAS'] ?? [];
            $capabilities['supports_sepa_accounts'] = !empty($hispasParams);

            // Determine transaction support status
            $capabilities['mt940_supported'] = $this->checkMT940Support($capabilities['mt940_versions']);
            $capabilities['camt_supported'] = $this->checkCAMTSupport($capabilities['camt_versions']);
            $capabilities['transactions_supported'] = $capabilities['mt940_supported'] || $capabilities['camt_supported'];

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
                'mt940_versions' => $capabilities['mt940_versions'],
                'camt_versions' => $capabilities['camt_versions'],
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
     * Check if any MT940 version is supported by phpFinTS
     * phpFinTS supports HIKAZS versions 4, 5, 6, 7
     */
    private function checkMT940Support(array $versions): bool
    {
        $supportedVersions = [4, 5, 6, 7];
        foreach ($versions as $version) {
            if (in_array((int)$version, $supportedVersions)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if any CAMT version is supported by phpFinTS
     * phpFinTS supports HICAZS version 1
     */
    private function checkCAMTSupport(array $versions): bool
    {
        $supportedVersions = [1];
        foreach ($versions as $version) {
            if (in_array((int)$version, $supportedVersions)) {
                return true;
            }
        }
        return false;
    }
}
