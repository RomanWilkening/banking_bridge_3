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

    public const PRODUCT_NAME = 'BankingBridgeHA';
    public const PRODUCT_VERSION = '1.0.0';

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Create FinTS options object
     */
    private function createOptions(array $bankConfig): FinTsOptions
    {
        $options = new FinTsOptions();
        $options->url = $bankConfig['fints_url'];
        $options->bankCode = $bankConfig['bank_code'];
        $options->productName = self::PRODUCT_NAME;
        $options->productVersion = self::PRODUCT_VERSION;
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
            
            // Try to get TAN modes which requires initial connection
            $tanModes = $this->finTs->getTanModes();
            
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

            $accounts = $getSepaAccounts->getAccounts();
            $result = [];

            foreach ($accounts as $account) {
                $accountData = [
                    'account_number' => $account->getAccountNumber(),
                    'iban' => $account->getIban(),
                    'bic' => $account->getBic(),
                    'account_name' => $account->getAccountOwnerName() ?? 'Konto',
                    'owner_name' => $account->getAccountOwnerName(),
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
                        'account' => $account->getAccountNumber(),
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
                    return [
                        'amount' => $balance->getAmount()->toFloat(),
                        'date' => $balance->getDate()?->format('Y-m-d H:i:s')
                    ];
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
                $accounts = $action->getAccounts();
                $result = [];

                foreach ($accounts as $account) {
                    $result[] = [
                        'account_number' => $account->getAccountNumber(),
                        'iban' => $account->getIban(),
                        'bic' => $account->getBic(),
                        'account_name' => $account->getAccountOwnerName() ?? 'Konto',
                        'owner_name' => $account->getAccountOwnerName(),
                        'currency' => 'EUR',
                        'balance' => null,
                        'balance_date' => null
                    ];
                }

                return [
                    'success' => true,
                    'accounts' => $result,
                    'persisted_instance' => $this->finTs->persist()
                ];
            }

            return [
                'success' => true,
                'message' => 'TAN akzeptiert'
            ];

        } catch (Exception $e) {
            $this->logger->error('TAN submission failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'TAN-Fehler: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Extract TAN request information
     */
    private function extractTanRequest(BaseAction $action): array
    {
        $tanRequest = $action->getTanRequest();
        
        return [
            'challenge' => $tanRequest->getChallenge(),
            'challenge_html' => $tanRequest->getChallengeHtml(),
            'tan_medium' => $tanRequest->getTanMediumName()
        ];
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
