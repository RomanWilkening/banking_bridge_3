<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\DatabaseService;
use App\Services\FinTSService;
use App\Services\MqttService;
use Monolog\Logger;

class ApiController
{
    public function __construct(
        private DatabaseService $db,
        private FinTSService $fintsService,
        private MqttService $mqttService,
        private Logger $logger
    ) {}

    /**
     * Test bank connection
     */
    public function testConnection(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        if (empty($data['bank_code']) || empty($data['fints_url']) || 
            empty($data['username']) || empty($data['password'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Alle Felder müssen ausgefüllt sein'
            ], 400);
        }

        $result = $this->fintsService->testConnection([
            'bank_code' => $data['bank_code'],
            'fints_url' => $data['fints_url'],
            'username' => $data['username'],
            'password' => $data['password']
        ]);

        return $this->jsonResponse($response, $result);
    }

    /**
     * Get accounts from bank
     */
    public function getAccounts(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }

        // Try to reuse existing session (allows TAN-free access within 90-day window)
        $session = $this->db->getFinTSSession($bankId);
        $persistedInstance = $session ? $session['session_data'] : null;
        
        $this->logger->info('Fetching accounts', [
            'bank_id' => $bankId,
            'has_session' => $persistedInstance !== null
        ]);

        $result = $this->fintsService->getAccounts([
            'bank_code' => $bank['bank_code'],
            'fints_url' => $bank['fints_url'],
            'username' => $bank['username'],
            'password' => $bank['password']
        ], $persistedInstance);

        // Handle TAN requirement
        if (isset($result['needs_tan']) && $result['needs_tan']) {
            // Store session for TAN submission
            $this->db->saveFinTSSession(
                $bankId,
                $result['persisted_instance'],
                null,
                null
            );
            
            // Store action in session for retrieval during TAN submission
            $_SESSION['fints_action_' . $bankId] = $result['persisted_action'];
            
            return $this->jsonResponse($response, [
                'success' => false,
                'needs_tan' => true,
                'tan_request' => $result['tan_request']
            ]);
        }

        // Store accounts in database if successful
        if ($result['success'] && isset($result['accounts'])) {
            foreach ($result['accounts'] as $accountData) {
                $this->db->upsertAccount($bankId, $accountData);
            }
            
            // Update session (before removing from result)
            if (isset($result['persisted_instance'])) {
                $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            }
        }

        // Remove large/non-serializable data before JSON response
        unset($result['persisted_instance']);
        unset($result['persisted_action']);

        return $this->jsonResponse($response, $result);
    }
    
    /**
     * Sync account balances from bank
     */
    public function syncBalances(Request $request, Response $response, array $args): Response
    {
        $this->logger->info('=== SYNC BALANCES STARTED ===', ['args' => $args]);
        
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }
        
        // Try to use existing session (preserves kundensystemId for TAN-free access per PSD2)
        $existingSession = $this->db->getFinTSSession($bankId);
        $persistedInstance = $existingSession ? $existingSession['session_data'] : null;
        
        $this->logger->info('SyncBalances using session', [
            'has_existing_session' => $persistedInstance !== null
        ]);
        
        $result = $this->fintsService->fetchAccountBalances([
            'bank_code' => $bank['bank_code'],
            'fints_url' => $bank['fints_url'],
            'username' => $bank['username'],
            'password' => $bank['password']
        ], $persistedInstance);
        
        // Handle TAN requirement
        if (isset($result['needs_tan']) && $result['needs_tan']) {
            $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            $_SESSION['fints_action_' . $bankId] = $result['persisted_action'];
            
            return $this->jsonResponse($response, [
                'success' => false,
                'needs_tan' => true,
                'tan_request' => $result['tan_request']
            ]);
        }
        
        if (!$result['success']) {
            return $this->jsonResponse($response, $result);
        }
        
        // Update balances in database
        $updatedCount = 0;
        if (isset($result['balances'])) {
            foreach ($result['balances'] as $balanceData) {
                // Find account by IBAN
                $accounts = $this->db->getAccountsByBankId($bankId);
                foreach ($accounts as $account) {
                    if ($account['iban'] === $balanceData['iban']) {
                        $this->db->updateAccountBalance(
                            $account['id'],
                            $balanceData['balance'],
                            $balanceData['balance_date']
                        );
                        $updatedCount++;
                        $this->logger->info('Updated balance', [
                            'account_id' => $account['id'],
                            'iban' => $balanceData['iban'],
                            'balance' => $balanceData['balance']
                        ]);
                        break;
                    }
                }
            }
        }
        
        // Save session
        if (isset($result['persisted_instance'])) {
            $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
        }
        
        return $this->jsonResponse($response, [
            'success' => true,
            'message' => "Kontosalden aktualisiert",
            'updated_count' => $updatedCount,
            'balances' => $result['balances'] ?? []
        ]);
    }
    
    /**
     * Sync everything: balances, transactions, and depot holdings
     */
    public function syncAll(Request $request, Response $response, array $args): Response
    {
        $this->logger->info('=== SYNC ALL STARTED ===', ['args' => $args]);
        
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }
        
        // Get all accounts for this bank
        $accounts = $this->db->getAccountsByBankId($bankId);
        if (empty($accounts)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Keine Konten gefunden. Bitte zuerst Konten abrufen.'
            ], 400);
        }
        
        // Try to use existing session (preserves kundensystemId for TAN-free access per PSD2)
        $existingSession = $this->db->getFinTSSession($bankId);
        $persistedInstance = $existingSession ? $existingSession['session_data'] : null;
        
        $this->logger->info('=== SyncAll SESSION CHECK ===', [
            'bank_id' => $bankId,
            'has_existing_session' => $existingSession !== null,
            'session_data_length' => $persistedInstance ? strlen($persistedInstance) : 0,
            'session_created' => $existingSession['created_at'] ?? null,
            'session_expires' => $existingSession['expires_at'] ?? null
        ]);
        
        $result = $this->fintsService->syncAll(
            [
                'bank_code' => $bank['bank_code'],
                'fints_url' => $bank['fints_url'],
                'username' => $bank['username'],
                'password' => $bank['password']
            ],
            $accounts,
            $persistedInstance
        );
        
        // Handle TAN requirement
        if (isset($result['needs_tan']) && $result['needs_tan']) {
            $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            $_SESSION['fints_action_' . $bankId] = $result['persisted_action'];
            $_SESSION['fints_sync_all_bank_id'] = $bankId;
            
            return $this->jsonResponse($response, [
                'success' => false,
                'needs_tan' => true,
                'tan_request' => $result['tan_request']
            ]);
        }
        
        if (!$result['success']) {
            return $this->jsonResponse($response, $result);
        }
        
        // Process results using helper method
        return $this->processSyncAllResults($bankId, $result, $response);
    }
    
    /**
     * Get activity log for a bank
     */
    public function getActivityLog(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }
        
        $params = $request->getQueryParams();
        $limit = min(100, max(10, (int) ($params['limit'] ?? 100)));
        
        $activities = $this->db->getActivityLog($bankId, $limit);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'activities' => $activities,
            'count' => count($activities)
        ]);
    }
    
    /**
     * Process and store syncAll results
     * Used by both syncAll and checkDecoupled after TAN confirmation
     */
    private function processSyncAllResults(int $bankId, array $result, Response $response): Response
    {
        $stats = [
            'balances_updated' => 0,
            'transactions_new' => 0,
            'transactions_updated' => 0,
            'holdings_updated' => 0,
            'errors' => $result['results']['errors'] ?? []
        ];
        
        $results = $result['results'];
        
        // Update balances
        foreach ($results['balances'] as $accountId => $balance) {
            $this->db->updateAccountBalance($accountId, $balance['amount'], $balance['date']);
            $stats['balances_updated']++;
            
            $this->db->logActivity(
                'fetch_balance',
                'success',
                sprintf("Saldo: %.2f %s", $balance['amount'], $balance['currency'] ?? 'EUR'),
                $bankId,
                $accountId,
                ['balance' => $balance['amount'], 'date' => $balance['date']]
            );
        }
        
        // Save transactions
        foreach ($results['transactions'] as $accountId => $transactions) {
            $txResult = $this->db->saveTransactions($accountId, $transactions);
            $stats['transactions_new'] += $txResult['new'];
            $stats['transactions_updated'] += $txResult['updated'];
            
            $this->db->logActivity(
                'fetch_transactions',
                'success',
                "{$txResult['new']} neue, {$txResult['updated']} aktualisiert (von {$txResult['total']} abgerufen)",
                $bankId,
                $accountId,
                $txResult
            );
        }
        
        // Save holdings
        foreach ($results['holdings'] as $accountId => $holdings) {
            $count = $this->db->saveSecuritiesHoldings($accountId, $holdings);
            $stats['holdings_updated'] += $count;
            
            // Update depot total value as balance
            $totalValue = $this->db->getDepotTotalValue($accountId);
            if ($totalValue !== null) {
                $this->db->updateAccountBalance($accountId, $totalValue, date('Y-m-d H:i:s'));
            }
            
            $this->db->logActivity(
                'fetch_holdings',
                'success',
                sprintf("%d Wertpapiere, Gesamtwert: %.2f EUR", $count, $totalValue ?? 0),
                $bankId,
                $accountId,
                ['count' => $count, 'total_value' => $totalValue]
            );
        }
        
        // Log errors
        foreach ($stats['errors'] as $error) {
            $errorMsg = is_array($error) ? ($error['error'] ?? 'Unbekannter Fehler') : $error;
            $accountId = is_array($error) ? ($error['account_id'] ?? null) : null;
            $this->db->logActivity('sync_error', 'error', $errorMsg, $bankId, $accountId);
        }
        
        // Save session
        if (isset($result['persisted_instance'])) {
            $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
        }
        
        $this->logger->info('Sync all results processed', $stats);
        
        // Log the sync all summary
        $errorCount = count($stats['errors']);
        $this->db->logActivity(
            'sync_all',
            $errorCount === 0 ? 'success' : 'warning',
            sprintf("Sync abgeschlossen: %d Salden, %d neue TX, %d Wertpapiere%s",
                $stats['balances_updated'],
                $stats['transactions_new'],
                $stats['holdings_updated'],
                $errorCount > 0 ? " ({$errorCount} Fehler)" : ""
            ),
            $bankId,
            null,
            $stats
        );
        
        // Publish to MQTT if enabled
        if ($this->mqttService->isEnabled()) {
            $mqttResult = $this->mqttService->publishAccountBalances();
            $this->logger->info('MQTT publish after sync', $mqttResult);
        }
        
        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Synchronisierung abgeschlossen',
            'stats' => $stats
        ]);
    }

    /**
     * Submit TAN for ongoing action
     */
    public function submitTan(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $data = $request->getParsedBody();
        
        if (empty($data['tan'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'TAN ist erforderlich'
            ], 400);
        }

        $bank = $this->db->getBankById($bankId);
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }

        $session = $this->db->getFinTSSession($bankId);
        if (!$session) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Keine aktive Sitzung gefunden'
            ], 400);
        }

        // Get persisted action from session
        $persistedAction = $_SESSION['fints_action_' . $bankId] ?? null;
        if (!$persistedAction) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Keine aktive Aktion gefunden'
            ], 400);
        }

        // Build sync context if there's a pending sync operation
        $syncContext = null;
        $syncAllContext = null;
        $pendingSyncAccountId = $_SESSION['fints_sync_account_id'] ?? null;
        $pendingSyncAllBankId = $_SESSION['fints_sync_all_bank_id'] ?? null;

        if ($pendingSyncAllBankId && (int)$pendingSyncAllBankId === $bankId) {
            $accounts = $this->db->getAccountsByBankId($bankId);
            if (!empty($accounts)) {
                $syncAllContext = $accounts;
            }
        } elseif ($pendingSyncAccountId) {
            $account = $this->db->getAccountById($pendingSyncAccountId);
            if ($account) {
                try {
                    $syncFrom = isset($_SESSION['fints_sync_from']) ? new \DateTime($_SESSION['fints_sync_from']) : new \DateTime('-30 days');
                    $syncTo = isset($_SESSION['fints_sync_to']) ? new \DateTime($_SESSION['fints_sync_to']) : new \DateTime();
                } catch (\Exception $e) {
                    $syncFrom = new \DateTime('-30 days');
                    $syncTo = new \DateTime();
                }
                $syncContext = [
                    'account_identifier' => $account['iban'] ?? $account['account_number'],
                    'from' => $syncFrom,
                    'to' => $syncTo,
                    'account_id' => $pendingSyncAccountId
                ];
            }
        }

        $result = $this->fintsService->submitTan(
            [
                'bank_code' => $bank['bank_code'],
                'fints_url' => $bank['fints_url'],
                'username' => $bank['username'],
                'password' => $bank['password']
            ],
            $session['session_data'],
            $persistedAction,
            $data['tan'],
            $syncContext,
            $syncAllContext
        );

        // Handle another TAN requirement
        if (isset($result['needs_tan']) && $result['needs_tan']) {
            $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            $_SESSION['fints_action_' . $bankId] = $result['persisted_action'];
            
            return $this->jsonResponse($response, [
                'success' => false,
                'needs_tan' => true,
                'tan_request' => $result['tan_request']
            ]);
        }

        // Clean up session
        unset($_SESSION['fints_action_' . $bankId]);

        // Handle syncAll result
        if ($result['success'] && isset($result['is_sync_all']) && $result['is_sync_all']) {
            $this->logger->info('Processing syncAll results after TAN submission', [
                'has_results' => isset($result['results'])
            ]);
            
            // Clean up session markers
            unset($_SESSION['fints_sync_account_id']);
            unset($_SESSION['fints_sync_all_bank_id']);
            unset($_SESSION['fints_sync_from']);
            unset($_SESSION['fints_sync_to']);
            
            return $this->processSyncAllResults($bankId, $result, $response);
        }

        // Handle transaction sync result (has 'transactions' key)
        $pendingSyncAccountId = $_SESSION['fints_sync_account_id'] ?? null;
        if ($result['success'] && isset($result['transactions']) && $pendingSyncAccountId) {
            $this->logger->info('Received transactions after TAN submission', [
                'count' => count($result['transactions'])
            ]);
            
            $txResult = $this->db->saveTransactions($pendingSyncAccountId, $result['transactions']);
            $this->logger->info('Saved transactions after TAN', [
                'new' => $txResult['new'],
                'updated' => $txResult['updated'],
                'total' => $txResult['total']
            ]);
            
            // Update balance
            if (isset($result['balance'])) {
                $this->db->updateAccountBalance($pendingSyncAccountId, $result['balance'], $result['balance_date'] ?? null);
            }
            
            // Save session
            if (isset($result['persisted_instance'])) {
                $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            }
            
            // Clean up sync session data
            unset($_SESSION['fints_sync_account_id']);
            unset($_SESSION['fints_sync_from']);
            unset($_SESSION['fints_sync_to']);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Transaktionen synchronisiert',
                'new_count' => $txResult['new'],
                'updated_count' => $txResult['updated'],
                'count' => $txResult['total'],
                'balance' => $result['balance'] ?? null
            ]);
        }

        // Store accounts if we got them
        if ($result['success'] && isset($result['accounts'])) {
            foreach ($result['accounts'] as $accountData) {
                $this->db->upsertAccount($bankId, $accountData);
            }
            
            if (isset($result['persisted_instance'])) {
                $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            }
        }

        // Remove large/non-serializable data before JSON response
        unset($result['persisted_instance']);
        unset($result['persisted_action']);

        return $this->jsonResponse($response, $result);
    }

    /**
     * Check decoupled TAN status (for pushTAN app confirmation)
     */
    public function checkDecoupled(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }

        $session = $this->db->getFinTSSession($bankId);
        if (!$session) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Keine aktive Sitzung gefunden'
            ], 400);
        }

        // Get persisted action from session
        $persistedAction = $_SESSION['fints_action_' . $bankId] ?? null;
        if (!$persistedAction) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Keine aktive Aktion gefunden'
            ], 400);
        }

        // Check if there's a pending sync operation - if so, pass context to continue in same session
        $syncContext = null;
        $syncAllContext = null;
        $pendingSyncAccountId = $_SESSION['fints_sync_account_id'] ?? null;
        $pendingSyncAllBankId = $_SESSION['fints_sync_all_bank_id'] ?? null;
        
        // Check for syncAll operation
        if ($pendingSyncAllBankId && $pendingSyncAllBankId == $bankId) {
            $accounts = $this->db->getAccountsByBankId($bankId);
            if (!empty($accounts)) {
                $syncAllContext = $accounts;
                $this->logger->info('Will continue with FULL SYNC after TAN', [
                    'bank_id' => $bankId,
                    'accounts_count' => count($accounts)
                ]);
            }
        }
        // Check for single account sync operation
        elseif ($pendingSyncAccountId) {
            $account = $this->db->getAccountById($pendingSyncAccountId);
            if ($account) {
                $syncFrom = isset($_SESSION['fints_sync_from']) ? new \DateTime($_SESSION['fints_sync_from']) : new \DateTime('-30 days');
                $syncTo = isset($_SESSION['fints_sync_to']) ? new \DateTime($_SESSION['fints_sync_to']) : new \DateTime();
                $syncContext = [
                    'account_identifier' => $account['iban'] ?? $account['account_number'],
                    'from' => $syncFrom,
                    'to' => $syncTo,
                    'account_id' => $pendingSyncAccountId
                ];
                $this->logger->info('Will continue with sync after TAN', [
                    'account_id' => $pendingSyncAccountId,
                    'account_identifier' => $syncContext['account_identifier'],
                    'from' => $syncFrom->format('Y-m-d'),
                    'to' => $syncTo->format('Y-m-d')
                ]);
            }
        }

        $result = $this->fintsService->checkDecoupledStatus(
            [
                'bank_code' => $bank['bank_code'],
                'fints_url' => $bank['fints_url'],
                'username' => $bank['username'],
                'password' => $bank['password']
            ],
            $session['session_data'],
            $persistedAction,
            $syncContext,
            $syncAllContext
        );

        // Handle still pending
        if (isset($result['status']) && $result['status'] === 'pending') {
            // Update session data
            if (isset($result['persisted_instance'])) {
                $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            }
            if (isset($result['persisted_action'])) {
                $_SESSION['fints_action_' . $bankId] = $result['persisted_action'];
            }
            
            unset($result['persisted_instance']);
            unset($result['persisted_action']);
            
            return $this->jsonResponse($response, $result);
        }

        // Handle another TAN requirement (e.g., for transaction fetch after login TAN)
        if (isset($result['needs_tan']) && $result['needs_tan']) {
            $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            $_SESSION['fints_action_' . $bankId] = $result['persisted_action'];
            // Keep sync account ID for continued sync
            
            return $this->jsonResponse($response, [
                'success' => false,
                'needs_tan' => true,
                'tan_request' => $result['tan_request']
            ]);
        }

        // Clean up session markers
        unset($_SESSION['fints_action_' . $bankId]);
        unset($_SESSION['fints_sync_account_id']);
        unset($_SESSION['fints_sync_all_bank_id']);
        unset($_SESSION['fints_sync_from']);
        unset($_SESSION['fints_sync_to']);

        // Handle syncAll result
        if ($result['success'] && isset($result['is_sync_all']) && $result['is_sync_all']) {
            $this->logger->info('Processing syncAll results after TAN', [
                'has_results' => isset($result['results'])
            ]);
            
            return $this->processSyncAllResults($bankId, $result, $response);
        }

        // Handle transaction sync result (has 'transactions' key)
        if ($result['success'] && isset($result['transactions'])) {
            $this->logger->info('Received transactions from sync continuation', [
                'count' => count($result['transactions'])
            ]);
            
            $txResult = ['new' => 0, 'updated' => 0, 'total' => 0];
            
            // Save transactions
            if ($pendingSyncAccountId) {
                $txResult = $this->db->saveTransactions($pendingSyncAccountId, $result['transactions']);
                $this->logger->info('Saved transactions after TAN', [
                    'new' => $txResult['new'],
                    'updated' => $txResult['updated'],
                    'total' => $txResult['total']
                ]);
                
                // Update balance
                if (isset($result['balance'])) {
                    $this->db->updateAccountBalance($pendingSyncAccountId, $result['balance'], $result['balance_date'] ?? null);
                }
            }
            
            // Save session
            if (isset($result['persisted_instance'])) {
                $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Transaktionen synchronisiert',
                'new_count' => $txResult['new'],
                'updated_count' => $txResult['updated'],
                'count' => $txResult['total'],
                'balance' => $result['balance'] ?? null
            ]);
        }

        // Handle accounts result (has 'accounts' key)
        if ($result['success'] && isset($result['accounts'])) {
            foreach ($result['accounts'] as $accountData) {
                $this->db->upsertAccount($bankId, $accountData);
            }
            
            if (isset($result['persisted_instance'])) {
                $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            }
        }

        // Remove large/non-serializable data before JSON response
        unset($result['persisted_instance']);
        unset($result['persisted_action']);

        return $this->jsonResponse($response, $result);
    }

    /**
     * Reset/delete the FinTS session for a bank
     * This forces a fresh connection with new BPD/UPD on next request
     */
    public function resetSession(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }

        $this->db->deleteFinTSSession($bankId);
        
        // Also clear any session-related PHP session data
        unset($_SESSION['fints_action_' . $bankId]);
        unset($_SESSION['fints_sync_account_id']);
        unset($_SESSION['fints_sync_all_bank_id']);
        unset($_SESSION['fints_sync_from']);
        unset($_SESSION['fints_sync_to']);
        
        $this->logger->info('FinTS session reset', ['bank_id' => $bankId, 'bank_name' => $bank['name']]);

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Session zurückgesetzt. Beim nächsten Abruf werden neue Bankdaten geladen.'
        ]);
    }

    /**
     * Get transactions for an account (from database)
     */
    public function getTransactions(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $account = $this->db->getAccountById($accountId);
        
        if (!$account) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Konto nicht gefunden'
            ], 404);
        }

        $params = $request->getQueryParams();
        $limit = min(100, max(1, (int) ($params['limit'] ?? 30)));
        $offset = max(0, (int) ($params['offset'] ?? 0));

        $transactions = $this->db->getTransactionsByAccountId($accountId, $limit, $offset);
        $total = $this->db->getTransactionCount($accountId);

        return $this->jsonResponse($response, [
            'success' => true,
            'transactions' => $transactions,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    /**
     * Sync account - fetch transactions from bank
     */
    public function syncAccount(Request $request, Response $response, array $args): Response
    {
        $this->logger->info('=== SYNC ACCOUNT STARTED ===', ['args' => $args]);
        
        $accountId = (int) $args['id'];
        $account = $this->db->getAccountById($accountId);
        
        if (!$account) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Konto nicht gefunden'
            ], 404);
        }

        $bank = $this->db->getBankById($account['bank_id']);
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }

        // Parse request body for date range
        $data = $request->getParsedBody() ?? [];
        $from = isset($data['from']) ? new \DateTime($data['from']) : new \DateTime('-30 days');
        $to = isset($data['to']) ? new \DateTime($data['to']) : new \DateTime();

        // Try to use existing session (preserves kundensystemId for TAN-free access per PSD2)
        $existingSession = $this->db->getFinTSSession($bank['id']);
        $persistedInstance = $existingSession ? $existingSession['session_data'] : null;
        
        $this->logger->info('=== SyncAccount SESSION CHECK ===', [
            'bank_id' => $bank['id'],
            'account_id' => $accountId,
            'has_existing_session' => $existingSession !== null,
            'session_data_length' => $persistedInstance ? strlen($persistedInstance) : 0,
            'session_created' => $existingSession['created_at'] ?? null
        ]);

        // Use the combined sync method in FinTSService
        $result = $this->fintsService->syncAccountTransactions(
            [
                'bank_code' => $bank['bank_code'],
                'fints_url' => $bank['fints_url'],
                'username' => $bank['username'],
                'password' => $bank['password']
            ],
            $account['iban'] ?? $account['account_number'],
            $from,
            $to,
            $persistedInstance
        );

        if (isset($result['needs_tan'])) {
            $this->db->saveFinTSSession($bank['id'], $result['persisted_instance']);
            $_SESSION['fints_action_' . $bank['id']] = $result['persisted_action'];
            $_SESSION['fints_sync_account_id'] = $accountId;
            $_SESSION['fints_sync_from'] = $from->format('Y-m-d');
            $_SESSION['fints_sync_to'] = $to->format('Y-m-d');
            
            return $this->jsonResponse($response, [
                'success' => false,
                'needs_tan' => true,
                'tan_request' => $result['tan_request']
            ]);
        }

        if (!$result['success']) {
            unset($result['persisted_instance']);
            return $this->jsonResponse($response, $result);
        }

        $txResult = ['new' => 0, 'updated' => 0, 'total' => 0];
        
        // Save transactions to database
        if (isset($result['transactions'])) {
            $txResult = $this->db->saveTransactions($accountId, $result['transactions']);
            $this->logger->info('Saved transactions', [
                'new' => $txResult['new'],
                'updated' => $txResult['updated'],
                'total' => $txResult['total'],
                'account_id' => $accountId
            ]);
        }

        // Update account balance
        if (isset($result['balance'])) {
            $this->db->updateAccountBalance($accountId, $result['balance'], $result['balance_date'] ?? null);
        }

        // Save session for future use
        if (isset($result['persisted_instance'])) {
            $this->db->saveFinTSSession($bank['id'], $result['persisted_instance']);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Transaktionen synchronisiert',
            'new_count' => $txResult['new'],
            'updated_count' => $txResult['updated'],
            'count' => $txResult['total'],
            'balance' => $result['balance'] ?? null
        ]);
    }

    /**
     * Get bank capabilities (what features the bank supports)
     */
    public function getBankCapabilities(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }

        // Check if we have cached capabilities
        $cached = $this->db->getBankCapabilities($bankId);
        $forceRefresh = ($request->getQueryParams()['refresh'] ?? '') === '1';
        
        // Return cached if available and not forcing refresh (cache for 24 hours)
        if ($cached && !$forceRefresh) {
            $lastUpdated = strtotime($cached['last_updated'] ?? '');
            $cacheAge = time() - $lastUpdated;
            
            if ($cacheAge < 86400) { // 24 hours
                return $this->jsonResponse($response, [
                    'success' => true,
                    'capabilities' => $cached,
                    'cached' => true,
                    'cache_age' => $cacheAge
                ]);
            }
        }

        // Fetch fresh capabilities from bank
        $result = $this->fintsService->getBankCapabilities([
            'bank_code' => $bank['bank_code'],
            'fints_url' => $bank['fints_url'],
            'username' => $bank['username'],
            'password' => $bank['password']
        ]);

        if (!$result['success']) {
            // If we have cached data, return it with error note
            if ($cached) {
                return $this->jsonResponse($response, [
                    'success' => true,
                    'capabilities' => $cached,
                    'cached' => true,
                    'refresh_error' => $result['message'] ?? 'Aktualisierung fehlgeschlagen'
                ]);
            }
            return $this->jsonResponse($response, $result);
        }

        // Save to database
        $this->db->saveBankCapabilities($bankId, $result['capabilities']);

        return $this->jsonResponse($response, [
            'success' => true,
            'capabilities' => $result['capabilities'],
            'cached' => false
        ]);
    }
    
    /**
     * Sync depot holdings from bank
     */
    public function syncDepotHoldings(Request $request, Response $response, array $args): Response
    {
        $this->logger->info('=== SYNC DEPOT STARTED ===', ['args' => $args]);
        
        $accountId = (int) $args['id'];
        $account = $this->db->getAccountById($accountId);
        
        if (!$account) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Konto nicht gefunden'
            ], 404);
        }
        
        if (($account['account_type'] ?? '') !== 'depot') {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Dieses Konto ist kein Depot'
            ], 400);
        }
        
        $bank = $this->db->getBankById($account['bank_id']);
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }
        
        // Try to use existing session (preserves kundensystemId for TAN-free access per PSD2)
        $existingSession = $this->db->getFinTSSession($bank['id']);
        $persistedInstance = $existingSession ? $existingSession['session_data'] : null;
        
        $this->logger->info('SyncDepotHoldings using session', [
            'has_existing_session' => $persistedInstance !== null
        ]);
        
        $result = $this->fintsService->getDepotHoldings(
            [
                'bank_code' => $bank['bank_code'],
                'fints_url' => $bank['fints_url'],
                'username' => $bank['username'],
                'password' => $bank['password']
            ],
            $account['account_number'],
            $persistedInstance
        );
        
        // Handle TAN requirement
        if (isset($result['needs_tan']) && $result['needs_tan']) {
            $this->db->saveFinTSSession($bank['id'], $result['persisted_instance']);
            $_SESSION['fints_action_' . $bank['id']] = $result['persisted_action'];
            $_SESSION['fints_depot_account_id'] = $accountId;
            
            return $this->jsonResponse($response, [
                'success' => false,
                'needs_tan' => true,
                'tan_request' => $result['tan_request']
            ]);
        }
        
        if (!$result['success']) {
            return $this->jsonResponse($response, $result);
        }
        
        // Save holdings to database
        if (isset($result['holdings'])) {
            $count = $this->db->saveSecuritiesHoldings($accountId, $result['holdings']);
            $this->logger->info('Saved depot holdings', ['count' => $count, 'account_id' => $accountId]);
            
            // Update depot total value as balance
            $totalValue = $this->db->getDepotTotalValue($accountId);
            if ($totalValue !== null) {
                $this->db->updateAccountBalance($accountId, $totalValue, date('Y-m-d H:i:s'));
            }
        }
        
        // Save session
        if (isset($result['persisted_instance'])) {
            $this->db->saveFinTSSession($bank['id'], $result['persisted_instance']);
        }
        
        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Depotbestand synchronisiert',
            'count' => count($result['holdings'] ?? []),
            'total_value' => $this->db->getDepotTotalValue($accountId)
        ]);
    }
    
    /**
     * Get depot holdings from database
     */
    public function getDepotHoldings(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $account = $this->db->getAccountById($accountId);
        
        if (!$account) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Konto nicht gefunden'
            ], 404);
        }
        
        $holdings = $this->db->getSecuritiesHoldings($accountId);
        $totalValue = $this->db->getDepotTotalValue($accountId);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'holdings' => $holdings,
            'total_value' => $totalValue,
            'count' => count($holdings)
        ]);
    }

    /**
     * Run automatic sync for all banks
     * This is called by the cron job or manually from settings
     */
    public function runAutoSync(Request $request, Response $response): Response
    {
        $this->logger->info('=== AUTO SYNC STARTED ===');
        
        $banks = $this->db->getAllBanks();
        
        if (empty($banks)) {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Keine Banken konfiguriert',
                'results' => []
            ]);
        }
        
        $results = [];
        $totalStats = [
            'banks_synced' => 0,
            'banks_skipped' => 0,
            'balances_updated' => 0,
            'transactions_new' => 0,
            'holdings_updated' => 0,
            'errors' => []
        ];
        
        foreach ($banks as $bank) {
            $bankId = $bank['id'];
            $bankName = $bank['name'];
            
            $this->logger->info('Auto-sync processing bank', ['bank_id' => $bankId, 'name' => $bankName]);
            
            // Get accounts for this bank
            $accounts = $this->db->getAccountsByBankId($bankId);
            if (empty($accounts)) {
                $this->logger->info('Skipping bank - no accounts', ['bank_id' => $bankId]);
                $results[$bankName] = ['status' => 'skipped', 'reason' => 'Keine Konten'];
                $totalStats['banks_skipped']++;
                continue;
            }
            
            // Check if any account requires manual TAN approval
            $hasManualTanApproval = !empty(array_filter($accounts, function($account) {
                return !empty($account['tan_manual_approval']);
            }));
            
            // Try to use existing session
            $existingSession = $this->db->getFinTSSession($bankId);
            $persistedInstance = $existingSession ? $existingSession['session_data'] : null;
            
            try {
                $result = $this->fintsService->syncAll(
                    [
                        'bank_code' => $bank['bank_code'],
                        'fints_url' => $bank['fints_url'],
                        'username' => $bank['username'],
                        'password' => $bank['password']
                    ],
                    $accounts,
                    $persistedInstance
                );
                
                // If TAN is required, skip this bank
                if (isset($result['needs_tan']) && $result['needs_tan']) {
                    if ($hasManualTanApproval) {
                        $this->logger->info('Auto-sync: TAN required, manual approval configured', ['bank_id' => $bankId]);
                        $results[$bankName] = ['status' => 'skipped', 'reason' => 'TAN erforderlich – manuelle Freigabe konfiguriert'];
                    } else {
                        $this->logger->info('Auto-sync: TAN required, skipping', ['bank_id' => $bankId]);
                        $results[$bankName] = ['status' => 'skipped', 'reason' => 'TAN erforderlich'];
                    }
                    $totalStats['banks_skipped']++;
                    
                    $this->db->logActivity(
                        'auto_sync_skipped',
                        'warning',
                        'Auto-Sync übersprungen – TAN erforderlich',
                        $bankId
                    );
                    continue;
                }
                
                if (!$result['success']) {
                    $this->logger->warning('Auto-sync failed for bank', [
                        'bank_id' => $bankId,
                        'error' => $result['message'] ?? 'Unknown'
                    ]);
                    $results[$bankName] = ['status' => 'error', 'error' => $result['message'] ?? 'Unbekannter Fehler'];
                    $totalStats['errors'][] = "{$bankName}: " . ($result['message'] ?? 'Unbekannter Fehler');
                    continue;
                }
                
                // Process successful results
                $bankStats = $this->processAutoSyncResults($bankId, $result);
                $results[$bankName] = ['status' => 'success', 'stats' => $bankStats];
                
                $totalStats['banks_synced']++;
                $totalStats['balances_updated'] += $bankStats['balances_updated'];
                $totalStats['transactions_new'] += $bankStats['transactions_new'];
                $totalStats['holdings_updated'] += $bankStats['holdings_updated'];
                
            } catch (\Throwable $e) {
                $this->logger->error('Auto-sync exception', [
                    'bank_id' => $bankId,
                    'error' => $e->getMessage()
                ]);
                $results[$bankName] = ['status' => 'error', 'error' => $e->getMessage()];
                $totalStats['errors'][] = "{$bankName}: {$e->getMessage()}";
            }
        }
        
        // Update last run timestamp
        $this->db->setSetting('auto_sync_last_run', date('d.m.Y H:i'));
        
        $this->logger->info('=== AUTO SYNC COMPLETED ===', $totalStats);
        
        // Publish to MQTT if enabled
        if ($this->mqttService->isEnabled()) {
            $mqttResult = $this->mqttService->publishAccountBalances();
            $this->logger->info('MQTT publish after auto-sync', $mqttResult);
        }
        
        // Create summary message
        $message = sprintf(
            '%d Bank(en) synchronisiert, %d übersprungen. %d Salden, %d neue Transaktionen, %d Wertpapiere.',
            $totalStats['banks_synced'],
            $totalStats['banks_skipped'],
            $totalStats['balances_updated'],
            $totalStats['transactions_new'],
            $totalStats['holdings_updated']
        );
        
        if (!empty($totalStats['errors'])) {
            $message .= ' ' . count($totalStats['errors']) . ' Fehler.';
        }
        
        return $this->jsonResponse($response, [
            'success' => true,
            'message' => $message,
            'stats' => $totalStats,
            'results' => $results
        ]);
    }
    
    /**
     * Process auto-sync results and save to database
     */
    private function processAutoSyncResults(int $bankId, array $result): array
    {
        $stats = [
            'balances_updated' => 0,
            'transactions_new' => 0,
            'transactions_updated' => 0,
            'holdings_updated' => 0
        ];
        
        $results = $result['results'] ?? [];
        
        // Update balances
        foreach ($results['balances'] ?? [] as $accountId => $balance) {
            $this->db->updateAccountBalance($accountId, $balance['amount'], $balance['date']);
            $stats['balances_updated']++;
        }
        
        // Save transactions
        foreach ($results['transactions'] ?? [] as $accountId => $transactions) {
            $txResult = $this->db->saveTransactions($accountId, $transactions);
            $stats['transactions_new'] += $txResult['new'];
            $stats['transactions_updated'] += $txResult['updated'];
        }
        
        // Save holdings
        foreach ($results['holdings'] ?? [] as $accountId => $holdings) {
            $count = $this->db->saveSecuritiesHoldings($accountId, $holdings);
            $stats['holdings_updated'] += $count;
            
            $totalValue = $this->db->getDepotTotalValue($accountId);
            if ($totalValue !== null) {
                $this->db->updateAccountBalance($accountId, $totalValue, date('Y-m-d H:i:s'));
            }
        }
        
        // Save session
        if (isset($result['persisted_instance'])) {
            $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
        }
        
        // Log activity
        $this->db->logActivity(
            'auto_sync',
            'success',
            sprintf("Auto-Sync: %d Salden, %d neue TX, %d Wertpapiere",
                $stats['balances_updated'],
                $stats['transactions_new'],
                $stats['holdings_updated']
            ),
            $bankId,
            null,
            $stats
        );
        
        return $stats;
    }
    
    /**
     * Get auto-sync status
     */
    public function getAutoSyncStatus(Request $request, Response $response): Response
    {
        $enabled = $this->db->getSetting('auto_sync_enabled', '0') === '1';
        $interval = $this->db->getSetting('auto_sync_interval', '30');
        $lastRun = $this->db->getSetting('auto_sync_last_run', '');
        
        return $this->jsonResponse($response, [
            'success' => true,
            'enabled' => $enabled,
            'interval' => (int) $interval,
            'last_run' => $lastRun
        ]);
    }

    /**
     * Get comprehensive cron job status
     */
    public function getCronStatus(Request $request, Response $response): Response
    {
        // Check if cron daemon is running (try multiple detection methods)
        $cronRunning = false;
        $output = [];
        exec('pgrep -x cron 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0) {
            $cronRunning = true;
        } else {
            // Some systems use 'crond' instead of 'cron'
            $output = [];
            exec('pgrep -x crond 2>/dev/null', $output, $returnCode);
            if ($returnCode === 0) {
                $cronRunning = true;
            } else {
                // Fallback: check via pidof (works even without procps)
                $output = [];
                exec('pidof cron crond 2>/dev/null', $output, $returnCode);
                if ($returnCode === 0) {
                    $cronRunning = true;
                } else {
                    // Fallback: check if cron service is active
                    $output = [];
                    exec('service cron status 2>/dev/null', $output, $returnCode);
                    if ($returnCode === 0) {
                        $cronRunning = true;
                    }
                }
            }
        }

        // Check cron configuration files
        $cronFiles = [];
        $cronDir = '/etc/cron.d';
        if (is_dir($cronDir)) {
            foreach (['auto-sync', 'mqtt-publish'] as $file) {
                $path = "$cronDir/$file";
                $cronFiles[$file] = [
                    'exists' => file_exists($path),
                    'readable' => is_readable($path),
                    'content' => file_exists($path) ? trim(file_get_contents($path)) : null,
                    'permissions' => file_exists($path) ? substr(sprintf('%o', fileperms($path)), -4) : null
                ];
            }
        }

        // Check system cron log (try syslog first, then cron.log)
        $cronSyslog = $this->readLastLogLines('/var/log/syslog', 20);
        if (empty($cronSyslog)) {
            $cronSyslog = $this->readLastLogLines('/var/log/cron.log', 20);
        }
        $cronSyslog = array_filter($cronSyslog, fn($line) => stripos($line, 'cron') !== false || stripos($line, 'CRON') !== false);

        // Auto-sync status
        $autoSyncEnabled = $this->db->getSetting('auto_sync_enabled', '0') === '1';
        $autoSyncInterval = (int) $this->db->getSetting('auto_sync_interval', '30');
        $autoSyncLastRun = $this->db->getSetting('auto_sync_last_run', '');
        $autoSyncLastRunTimestamp = (int) $this->db->getSetting('auto_sync_last_run_timestamp', '0');
        $autoSyncLastStatus = $this->db->getSetting('auto_sync_last_status', 'unknown');
        $autoSyncLastError = $this->db->getSetting('auto_sync_last_error', '');

        // MQTT publish status
        $mqttEnabled = $this->db->getSetting('mqtt_enabled', '0') === '1';
        $mqttAutoPublishEnabled = $this->db->getSetting('mqtt_auto_publish_enabled', '1') === '1';
        $mqttInterval = (int) $this->db->getSetting('mqtt_auto_publish_interval', '1');
        $mqttLastPublish = $this->db->getSetting('mqtt_last_publish', '');
        $mqttLastPublishTimestamp = (int) $this->db->getSetting('mqtt_last_publish_timestamp', '0');
        $mqttLastStatus = $this->db->getSetting('mqtt_last_status', 'unknown');
        $mqttLastError = $this->db->getSetting('mqtt_last_error', '');

        // Calculate next run times
        $now = time();
        $autoSyncNextRun = null;
        $mqttNextRun = null;

        if ($autoSyncEnabled && $autoSyncLastRunTimestamp > 0) {
            $autoSyncNextRun = $autoSyncLastRunTimestamp + ($autoSyncInterval * 60);
            if ($autoSyncNextRun < $now) {
                $autoSyncNextRun = $now; // Overdue
            }
        }

        if ($mqttEnabled && $mqttAutoPublishEnabled && $mqttLastPublishTimestamp > 0) {
            $mqttNextRun = $mqttLastPublishTimestamp + ($mqttInterval * 60);
            if ($mqttNextRun < $now) {
                $mqttNextRun = $now; // Overdue
            }
        }

        // Read last few lines from log files
        $autoSyncLog = $this->readLastLogLines('/var/log/auto-sync.log', 10);
        $mqttLog = $this->readLastLogLines('/var/log/mqtt-publish.log', 10);

        return $this->jsonResponse($response, [
            'success' => true,
            'cron_daemon_running' => $cronRunning,
            'cron_files' => $cronFiles,
            'cron_syslog' => array_values($cronSyslog),
            'auto_sync' => [
                'enabled' => $autoSyncEnabled,
                'interval_minutes' => $autoSyncInterval,
                'last_run' => $autoSyncLastRun,
                'last_run_timestamp' => $autoSyncLastRunTimestamp,
                'next_run_timestamp' => $autoSyncNextRun,
                'last_status' => $autoSyncLastStatus,
                'last_error' => $autoSyncLastError,
                'recent_log' => $autoSyncLog
            ],
            'mqtt_publish' => [
                'enabled' => $mqttEnabled && $mqttAutoPublishEnabled,
                'mqtt_enabled' => $mqttEnabled,
                'auto_publish_enabled' => $mqttAutoPublishEnabled,
                'interval_minutes' => $mqttInterval,
                'last_run' => $mqttLastPublish,
                'last_run_timestamp' => $mqttLastPublishTimestamp,
                'next_run_timestamp' => $mqttNextRun,
                'last_status' => $mqttLastStatus,
                'last_error' => $mqttLastError,
                'recent_log' => $mqttLog
            ],
            'server_time' => date('d.m.Y H:i:s'),
            'server_timestamp' => $now
        ]);
    }

    /**
     * Read last N lines from a log file (memory-efficient using tail)
     */
    private function readLastLogLines(string $path, int $lines = 10): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $lines = max(1, (int) $lines);
        $escapedPath = escapeshellarg($path);
        $escapedLines = escapeshellarg((string) $lines);
        $output = [];
        exec("tail -n {$escapedLines} {$escapedPath} 2>/dev/null", $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        return array_values(array_filter($output, fn($line) => trim($line) !== ''));
    }

    /**
     * Helper to create JSON response
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        // Remove non-serializable data (like persisted_instance which can be very large)
        unset($data['persisted_instance']);
        
        // Ensure all data is JSON serializable
        $data = $this->sanitizeForJson($data);
        
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        
        if ($json === false) {
            $this->logger->error('JSON encode failed', ['error' => json_last_error_msg()]);
            $json = json_encode([
                'success' => false,
                'message' => 'Interner Fehler bei der Datenverarbeitung'
            ]);
        }
        
        $response->getBody()->write($json);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Recursively sanitize data for JSON encoding
     */
    private function sanitizeForJson($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->sanitizeForJson($value);
            }
            return $result;
        }
        
        if (is_object($data)) {
            // Convert objects to string representation or null
            if (method_exists($data, '__toString')) {
                return (string) $data;
            }
            if ($data instanceof \DateTime || $data instanceof \DateTimeInterface) {
                return $data->format('Y-m-d H:i:s');
            }
            return null;
        }
        
        if (is_resource($data)) {
            return null;
        }
        
        // Handle non-UTF8 strings
        if (is_string($data)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        
        return $data;
    }
    
    // =====================================
    // Rename & Link API
    // =====================================
    
    /**
     * Update bank (rename)
     * PATCH /api/banks/{id}
     */
    public function updateBank(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }
        
        $data = $request->getParsedBody() ?? [];
        
        if (isset($data['name']) && !empty(trim($data['name']))) {
            $this->db->renameBank($bankId, $data['name']);
        }
        
        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Bank aktualisiert'
        ]);
    }
    
    /**
     * Update account (rename, link to depot)
     * PATCH /api/accounts/{id}
     */
    public function updateAccount(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $account = $this->db->getAccountById($accountId);
        
        if (!$account) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Konto nicht gefunden'
            ], 404);
        }
        
        $data = $request->getParsedBody() ?? [];
        
        // Rename
        if (array_key_exists('name', $data)) {
            $newName = !empty(trim($data['name'])) ? trim($data['name']) : null;
            $this->db->renameAccount($accountId, $newName);
        }
        
        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Konto aktualisiert'
        ]);
    }
    
    /**
     * Link account to depot
     * POST /api/accounts/{id}/link-depot
     */
    public function linkAccountToDepot(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $account = $this->db->getAccountById($accountId);
        
        if (!$account) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Konto nicht gefunden'
            ], 404);
        }
        
        // Depots cannot be linked to other depots
        if ($account['account_type'] === 'depot') {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Depots können nicht verknüpft werden'
            ], 400);
        }
        
        $data = $request->getParsedBody() ?? [];
        $depotId = isset($data['depot_id']) ? (int) $data['depot_id'] : null;
        
        // Validate depot exists if linking
        if ($depotId !== null && $depotId !== 0) {
            $depot = $this->db->getAccountById($depotId);
            if (!$depot || $depot['account_type'] !== 'depot') {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Depot nicht gefunden'
                ], 404);
            }
        } else {
            $depotId = null; // Unlink
        }
        
        $this->db->linkAccountToDepot($accountId, $depotId);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'message' => $depotId ? 'Konto mit Depot verknüpft' : 'Verknüpfung aufgehoben',
            'linked_depot_id' => $depotId
        ]);
    }
    
    /**
     * Get depots available for linking
     * GET /api/depots-for-linking
     */
    public function getDepotsForLinking(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $excludeId = isset($params['exclude']) ? (int) $params['exclude'] : 0;
        
        $depots = $this->db->getDepotsForLinking($excludeId);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'depots' => array_map(fn($d) => [
                'id' => (int) $d['id'],
                'name' => $d['name'],
                'account_number' => $d['account_number'],
                'sub_account' => $d['sub_account'],
                'bank' => $d['bank_name'],
                'label' => $d['name'] . ' (' . $d['bank_name'] . ')'
            ], $depots)
        ]);
    }
    
    // =====================================
    // Public Depot API (v1)
    // =====================================
    
    /**
     * List all depots
     * GET /api/v1/depots
     */
    public function listDepots(Request $request, Response $response): Response
    {
        $depots = $this->db->getAllDepots();
        
        return $this->jsonResponse($response, [
            'success' => true,
            'count' => count($depots),
            'depots' => array_map(function($d) {
                $totals = $this->db->getDepotTotalValueWithLinked((int) $d['id']);
                return [
                    'id' => (int) $d['id'],
                    'name' => $d['display_name'] ?? $d['account_name'],
                    'account_number' => $d['account_number'],
                    'sub_account' => $d['sub_account'],
                    'bank' => $d['bank_name'],
                    'bank_code' => $d['bank_code'],
                    'securities_value' => round($totals['securities_value'], 2),
                    'linked_accounts_value' => round($totals['linked_accounts_value'], 2),
                    'total_value' => round($totals['total_value'], 2),
                    'linked_accounts_count' => $totals['linked_accounts_count'],
                    'currency' => $d['currency'] ?? 'EUR',
                    'last_update' => $d['balance_date'],
                ];
            }, $depots)
        ]);
    }
    
    /**
     * Get a single depot with summary
     * GET /api/v1/depots/{id}
     */
    public function getDepot(Request $request, Response $response, array $args): Response
    {
        $depotId = (int) $args['id'];
        $depot = $this->db->getAccountWithBank($depotId);
        
        if (!$depot || $depot['account_type'] !== 'depot') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Depot nicht gefunden'
            ], 404);
        }
        
        $holdings = $this->db->getSecuritiesHoldings($depotId);
        $linkedAccounts = $this->db->getLinkedAccounts($depotId);
        $totals = $this->db->getDepotTotalValueWithLinked($depotId);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'depot' => [
                'id' => (int) $depot['id'],
                'name' => $depot['custom_name'] ?? $depot['account_name'],
                'account_number' => $depot['account_number'],
                'sub_account' => $depot['sub_account'],
                'bank' => $depot['bank_name'],
                'bank_code' => $depot['bank_code'],
                'securities_value' => round($totals['securities_value'], 2),
                'linked_accounts_value' => round($totals['linked_accounts_value'], 2),
                'total_value' => round($totals['total_value'], 2),
                'currency' => $depot['currency'] ?? 'EUR',
                'last_update' => $depot['balance_date'],
                'holdings_count' => count($holdings),
                'linked_accounts_count' => count($linkedAccounts),
            ]
        ]);
    }
    
    /**
     * List holdings for a specific depot
     * GET /api/v1/depots/{id}/holdings
     */
    public function listDepotHoldings(Request $request, Response $response, array $args): Response
    {
        $depotId = (int) $args['id'];
        $depot = $this->db->getAccountWithBank($depotId);
        
        if (!$depot || $depot['account_type'] !== 'depot') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Depot nicht gefunden'
            ], 404);
        }
        
        $holdings = $this->db->getSecuritiesHoldings($depotId);
        $linkedAccounts = $this->db->getLinkedAccounts($depotId);
        $totals = $this->db->getDepotTotalValueWithLinked($depotId);
        
        // Format securities holdings
        $formattedHoldings = array_map(function($h) {
            return [
                'type' => 'security',
                'isin' => $h['isin'],
                'wkn' => $h['wkn'],
                'name' => $h['name'],
                'quantity' => (float) $h['quantity'],
                'currency' => $h['currency'] ?? 'EUR',
                'current_price' => $h['current_price'] !== null ? round((float) $h['current_price'], 4) : null,
                'purchase_price' => $h['purchase_price'] !== null ? round((float) $h['purchase_price'], 4) : null,
                'total_value' => $h['total_value'] !== null ? round((float) $h['total_value'], 2) : null,
                'profit_loss' => $h['profit_loss'] !== null ? round((float) $h['profit_loss'], 2) : null,
                'profit_loss_percent' => $h['profit_loss_percent'] !== null ? round((float) $h['profit_loss_percent'], 2) : null,
                'price_date' => $h['price_date'],
                'updated_at' => $h['updated_at'],
            ];
        }, $holdings);
        
        // Add linked accounts as "cash" positions
        $linkedPositions = array_map(function($a) {
            $displayName = $a['custom_name'] ?? $a['account_name'] ?? 'Konto';
            return [
                'type' => 'cash',
                'isin' => null,
                'wkn' => null,
                'name' => $displayName . ' (' . $a['bank_name'] . ')',
                'quantity' => 1,
                'currency' => $a['currency'] ?? 'EUR',
                'current_price' => $a['balance'] !== null ? round((float) $a['balance'], 2) : null,
                'purchase_price' => null,
                'total_value' => $a['balance'] !== null ? round((float) $a['balance'], 2) : null,
                'profit_loss' => null,
                'profit_loss_percent' => null,
                'price_date' => $a['balance_date'],
                'updated_at' => $a['updated_at'],
                'linked_account' => [
                    'id' => (int) $a['id'],
                    'iban' => $a['iban'],
                    'account_type' => $a['account_type'],
                ]
            ];
        }, $linkedAccounts);
        
        // Combine all positions
        $allPositions = array_merge($formattedHoldings, $linkedPositions);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'depot' => [
                'id' => (int) $depot['id'],
                'name' => $depot['custom_name'] ?? $depot['account_name'],
                'bank' => $depot['bank_name'],
            ],
            'summary' => [
                'securities_value' => round($totals['securities_value'], 2),
                'linked_accounts_value' => round($totals['linked_accounts_value'], 2),
                'total_value' => round($totals['total_value'], 2),
                'securities_count' => count($holdings),
                'linked_accounts_count' => count($linkedAccounts),
            ],
            'count' => count($allPositions),
            'holdings' => $allPositions
        ]);
    }
    
    /**
     * List all holdings across all depots
     * GET /api/v1/holdings
     * Optional query params: ?isin=XX&wkn=XX&name=XX
     */
    public function listAllHoldings(Request $request, Response $response): Response
    {
        $holdings = $this->db->getAllSecuritiesHoldings();
        $params = $request->getQueryParams();
        
        // Optional filtering
        if (!empty($params['isin'])) {
            $isin = strtoupper($params['isin']);
            $holdings = array_filter($holdings, fn($h) => stripos($h['isin'] ?? '', $isin) !== false);
        }
        if (!empty($params['wkn'])) {
            $wkn = strtoupper($params['wkn']);
            $holdings = array_filter($holdings, fn($h) => stripos($h['wkn'] ?? '', $wkn) !== false);
        }
        if (!empty($params['name'])) {
            $name = $params['name'];
            $holdings = array_filter($holdings, fn($h) => stripos($h['name'] ?? '', $name) !== false);
        }
        
        // Re-index array after filtering
        $holdings = array_values($holdings);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'count' => count($holdings),
            'holdings' => array_map(function($h) {
                return [
                    'isin' => $h['isin'],
                    'wkn' => $h['wkn'],
                    'name' => $h['name'],
                    'quantity' => (float) $h['quantity'],
                    'currency' => $h['currency'] ?? 'EUR',
                    'current_price' => $h['current_price'] !== null ? round((float) $h['current_price'], 4) : null,
                    'purchase_price' => $h['purchase_price'] !== null ? round((float) $h['purchase_price'], 4) : null,
                    'total_value' => $h['total_value'] !== null ? round((float) $h['total_value'], 2) : null,
                    'profit_loss' => $h['profit_loss'] !== null ? round((float) $h['profit_loss'], 2) : null,
                    'profit_loss_percent' => $h['profit_loss_percent'] !== null ? round((float) $h['profit_loss_percent'], 2) : null,
                    'price_date' => $h['price_date'],
                    'depot' => [
                        'id' => (int) $h['depot_id'],
                        'name' => $h['depot_name'],
                        'number' => $h['depot_number'],
                        'bank' => $h['bank_name'],
                    ],
                    'updated_at' => $h['updated_at'],
                ];
            }, $holdings)
        ]);
    }
    
    // MQTT Methods
    
    /**
     * Test MQTT connection
     */
    public function testMqtt(Request $request, Response $response): Response
    {
        $result = $this->mqttService->testConnection();
        return $this->jsonResponse($response, $result);
    }
    
    /**
     * Publish account balances to MQTT
     */
    public function publishMqtt(Request $request, Response $response): Response
    {
        $result = $this->mqttService->publishAccountBalances();
        return $this->jsonResponse($response, $result);
    }
    
    /**
     * Get all MQTT-enabled accounts with their data (for debugging)
     */
    public function getMqttAccounts(Request $request, Response $response): Response
    {
        $accounts = $this->db->getMqttEnabledAccounts();
        
        return $this->jsonResponse($response, [
            'success' => true,
            'count' => count($accounts),
            'accounts' => array_map(function($a) {
                return [
                    'id' => $a['id'],
                    'bank_id' => $a['bank_id'],
                    'bank_name' => $a['bank_name'] ?? null,
                    'account_name' => $a['account_name'] ?? null,
                    'account_type' => $a['account_type'] ?? null,
                    'iban' => $a['iban'] ?? null,
                    'account_number' => $a['account_number'] ?? null,
                    'balance' => $a['balance'],
                    'balance_is_null' => $a['balance'] === null,
                    'balance_type' => gettype($a['balance']),
                    'currency' => $a['currency'] ?? 'EUR',
                    'mqtt_export' => $a['mqtt_export'] ?? 0,
                    'balance_date' => $a['balance_date'] ?? null,
                ];
            }, $accounts)
        ]);
    }
    
    /**
     * Set MQTT export flag for an account
     */
    public function setAccountMqttExport(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $account = $this->db->getAccountById($accountId);
        
        if (!$account) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Konto nicht gefunden'
            ], 404);
        }
        
        $data = $request->getParsedBody() ?? [];
        $enabled = !empty($data['enabled']);
        
        $this->db->setAccountMqttExport($accountId, $enabled);
        
        // If disabling, remove from Home Assistant discovery
        if (!$enabled) {
            $this->mqttService->removeAccountDiscovery($accountId);
        }
        
        return $this->jsonResponse($response, [
            'success' => true,
            'message' => $enabled ? 'MQTT-Export aktiviert' : 'MQTT-Export deaktiviert',
            'mqtt_export' => $enabled
        ]);
    }
    
    /**
     * Set TAN manual approval flag for an account
     */
    public function setAccountTanManualApproval(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $account = $this->db->getAccountById($accountId);
        
        if (!$account) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Konto nicht gefunden'
            ], 404);
        }
        
        $data = $request->getParsedBody() ?? [];
        $enabled = !empty($data['enabled']);
        
        $this->db->setAccountTanManualApproval($accountId, $enabled);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'message' => $enabled ? 'Manuelle TAN-Freigabe aktiviert – bei abgelaufener PIN wird kein automatischer TAN-Abruf durchgeführt' : 'Automatischer TAN-Abruf aktiviert',
            'tan_manual_approval' => $enabled
        ]);
    }
    
    /**
     * Get TAN session validity info for a bank
     */
    public function getTanSessionInfo(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }
        
        $session = $this->db->getFinTSSession($bankId);
        
        if (!$session) {
            return $this->jsonResponse($response, [
                'success' => true,
                'has_session' => false,
                'message' => 'Keine aktive TAN-Session vorhanden'
            ]);
        }
        
        $createdAt = new \DateTime($session['created_at']);
        $expiresAt = new \DateTime($session['expires_at']);
        $now = new \DateTime();
        
        $remainingInterval = $now->diff($expiresAt);
        $remainingDays = max(0, (int) $remainingInterval->format('%r%a'));
        
        $totalInterval = $createdAt->diff($expiresAt);
        $totalDays = (int) $totalInterval->format('%a');
        $elapsedInterval = $createdAt->diff($now);
        $elapsedDays = (int) $elapsedInterval->format('%a');
        
        $progressPercent = $totalDays > 0 ? min(100, round(($elapsedDays / $totalDays) * 100)) : 100;
        
        return $this->jsonResponse($response, [
            'success' => true,
            'has_session' => true,
            'created_at' => $createdAt->format('d.m.Y H:i'),
            'expires_at' => $expiresAt->format('d.m.Y H:i'),
            'remaining_days' => $remainingDays,
            'total_days' => $totalDays,
            'progress_percent' => $progressPercent,
            'tan_mode' => $session['tan_mode'] ?? null,
            'tan_medium' => $session['tan_medium'] ?? null,
            'is_valid' => $remainingDays > 0
        ]);
    }
    
    // Database Maintenance Methods
    
    /**
     * Get duplicate transactions summary
     */
    public function getDuplicates(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $accountId = isset($queryParams['account_id']) ? (int) $queryParams['account_id'] : null;
        $detailed = isset($queryParams['detailed']) && $queryParams['detailed'] === 'true';
        
        $summary = $this->db->getDuplicateSummary($accountId);
        
        $result = [
            'success' => true,
            'total_duplicate_groups' => $summary['total_duplicate_groups'],
            'total_duplicate_transactions' => $summary['total_duplicate_transactions'],
            'total_to_remove' => $summary['total_to_remove'],
        ];
        
        if ($detailed) {
            // Get full duplicate list with details
            $duplicates = $this->db->findDuplicateTransactions($accountId);
            $result['duplicates'] = $duplicates;
        } else {
            // Just return summary grouped info
            $result['groups'] = array_map(function($g) {
                return [
                    'account_id' => (int) $g['account_id'],
                    'booking_date' => $g['booking_date'],
                    'amount' => (float) $g['amount'],
                    'name' => $g['name'],
                    'description' => substr($g['description'] ?? '', 0, 50) . (strlen($g['description'] ?? '') > 50 ? '...' : ''),
                    'duplicate_count' => (int) $g['duplicate_count'],
                    'keep_id' => (int) $g['keep_id'],
                ];
            }, $summary['groups']);
        }
        
        return $this->jsonResponse($response, $result);
    }
    
    /**
     * Remove duplicate transactions
     */
    public function removeDuplicates(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $accountId = isset($data['account_id']) ? (int) $data['account_id'] : null;
        $dryRun = isset($data['dry_run']) && $data['dry_run'];
        
        // Get summary before removal
        $summaryBefore = $this->db->getDuplicateSummary($accountId);
        
        if ($dryRun) {
            return $this->jsonResponse($response, [
                'success' => true,
                'dry_run' => true,
                'would_remove' => $summaryBefore['total_to_remove'],
                'duplicate_groups' => $summaryBefore['total_duplicate_groups'],
                'message' => "Würde {$summaryBefore['total_to_remove']} doppelte Transaktionen entfernen (von {$summaryBefore['total_duplicate_transactions']} in {$summaryBefore['total_duplicate_groups']} Gruppen)."
            ]);
        }
        
        // Actually remove duplicates
        $removed = $this->db->removeDuplicateTransactions($accountId);
        
        $this->logger->info('Removed duplicate transactions', [
            'removed' => $removed,
            'account_id' => $accountId
        ]);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'removed' => $removed,
            'message' => "{$removed} doppelte Transaktionen wurden entfernt."
        ]);
    }
    
    /**
     * Regenerate transaction IDs with current algorithm
     */
    public function regenerateTransactionIds(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $accountId = isset($data['account_id']) ? (int) $data['account_id'] : null;
        
        $count = $this->db->regenerateTransactionIds($accountId);
        
        $this->logger->info('Regenerated transaction IDs', [
            'count' => $count,
            'account_id' => $accountId
        ]);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'regenerated' => $count,
            'message' => "Transaction-IDs für {$count} Transaktionen wurden neu generiert."
        ]);
    }
}
