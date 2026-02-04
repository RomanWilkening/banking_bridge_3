<?php
declare(strict_types=1);

namespace App\Services;

use Fhp\FinTs;
use Fhp\Options\FinTsOptions;
use Fhp\Options\Credentials;
use Fhp\Model\SEPAAccount;
use Fhp\Action\GetSEPAAccounts;
use Fhp\Action\GetBalance;
use Fhp\BaseAction;
use Fhp\CurlException;
use Fhp\Protocol\ServerException;
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
            $options = $this->createOptions($bankConfig);
            $credentials = $this->createCredentials($bankConfig);
            
            if ($persistedInstance) {
                $this->finTs = FinTs::new($options, $credentials, $persistedInstance);
            } else {
                $this->finTs = FinTs::new($options, $credentials);
                // Select TAN mode before login (required by phpFinTS)
                $this->selectTanMode();
            }

            // Login first (required before any other action)
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
            $this->logger->error('Failed to get accounts', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Fehler beim Abrufen der Konten: ' . $e->getMessage()
            ];
        }
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
     * Extract TAN request information
     */
    private function extractTanRequest($action): array
    {
        $tanRequest = $action->getTanRequest();
        
        // Check if this is a decoupled TAN (app confirmation without TAN input)
        $isDecoupled = false;
        
        // Method 1: Check TanRequest->isDecoupled()
        if (method_exists($tanRequest, 'isDecoupled')) {
            $isDecoupled = $tanRequest->isDecoupled();
            $this->logger->debug('isDecoupled from TanRequest', ['value' => $isDecoupled]);
        }
        
        // Method 2: Check TanMode->isDecoupled() via the action
        if (!$isDecoupled && method_exists($action, 'getTanMode')) {
            $tanMode = $action->getTanMode();
            if ($tanMode && method_exists($tanMode, 'isDecoupled')) {
                $isDecoupled = $tanMode->isDecoupled();
                $this->logger->debug('isDecoupled from TanMode', ['value' => $isDecoupled]);
            }
        }
        
        // Method 3: Check if challenge contains typical decoupled indicators
        $challenge = $tanRequest->getChallenge();
        if (!$isDecoupled && $challenge) {
            $decoupledKeywords = ['app', 'freigabe', 'bestätigen', 'push', 'mobil'];
            $challengeLower = strtolower($challenge);
            foreach ($decoupledKeywords as $keyword) {
                if (strpos($challengeLower, $keyword) !== false) {
                    $isDecoupled = true;
                    $this->logger->debug('isDecoupled detected from challenge text', ['keyword' => $keyword]);
                    break;
                }
            }
        }
        
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
}
