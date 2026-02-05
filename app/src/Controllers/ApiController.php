<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\DatabaseService;
use App\Services\FinTSService;
use Monolog\Logger;

class ApiController
{
    public function __construct(
        private DatabaseService $db,
        private FinTSService $fintsService,
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

        $result = $this->fintsService->submitTan(
            [
                'bank_code' => $bank['bank_code'],
                'fints_url' => $bank['fints_url'],
                'username' => $bank['username'],
                'password' => $bank['password']
            ],
            $session['session_data'],
            $persistedAction,
            $data['tan']
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
        $pendingSyncAccountId = $_SESSION['fints_sync_account_id'] ?? null;
        if ($pendingSyncAccountId) {
            $account = $this->db->getAccountById($pendingSyncAccountId);
            if ($account) {
                $syncContext = [
                    'account_identifier' => $account['iban'] ?? $account['account_number'],
                    'from' => new \DateTime('-30 days'),
                    'to' => new \DateTime(),
                    'account_id' => $pendingSyncAccountId
                ];
                $this->logger->info('Will continue with sync after TAN', [
                    'account_id' => $pendingSyncAccountId,
                    'account_identifier' => $syncContext['account_identifier']
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
            $syncContext
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

        // Handle transaction sync result (has 'transactions' key)
        if ($result['success'] && isset($result['transactions'])) {
            $this->logger->info('Received transactions from sync continuation', [
                'count' => count($result['transactions'])
            ]);
            
            // Save transactions
            if ($pendingSyncAccountId) {
                $count = $this->db->saveTransactions($pendingSyncAccountId, $result['transactions']);
                $this->logger->info('Saved transactions after TAN', ['count' => $count]);
                
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
                'count' => count($result['transactions']),
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

        // Always start fresh for sync to avoid session issues
        $this->db->deleteFinTSSession($bank['id']);

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
            $to
        );

        if (isset($result['needs_tan'])) {
            $this->db->saveFinTSSession($bank['id'], $result['persisted_instance']);
            $_SESSION['fints_action_' . $bank['id']] = $result['persisted_action'];
            $_SESSION['fints_sync_account_id'] = $accountId;
            
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

        // Save transactions to database
        if (isset($result['transactions'])) {
            $count = $this->db->saveTransactions($accountId, $result['transactions']);
            $this->logger->info('Saved transactions', ['count' => $count, 'account_id' => $accountId]);
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
            'count' => count($result['transactions'] ?? []),
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
        
        // Delete old session to ensure fresh start
        $this->db->deleteFinTSSession($bank['id']);
        
        $result = $this->fintsService->getDepotHoldings(
            [
                'bank_code' => $bank['bank_code'],
                'fints_url' => $bank['fints_url'],
                'username' => $bank['username'],
                'password' => $bank['password']
            ],
            $account['account_number']
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
}
