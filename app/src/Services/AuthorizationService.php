<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Owns each bank dialog independently of PHP sessions and the technical session cache.
 * A consumed continuation is never replayed after a crash or uncertain bank response.
 */
class AuthorizationService
{
    public function __construct(private DatabaseService $db, private FinTSService $fints)
    {
        $this->db->getPdo()->exec('CREATE TABLE IF NOT EXISTS fints_authorization_operations (
            bank_id INTEGER PRIMARY KEY, operation_id TEXT NOT NULL, owner_hash TEXT NOT NULL,
            request_id TEXT NOT NULL, status TEXT NOT NULL, payload TEXT,
            response TEXT, expires_at INTEGER NOT NULL, next_poll_at INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE
        )');
    }

    private function locked(int $bankId, callable $callback): array
    {
        $databases = $this->db->getPdo()->query('PRAGMA database_list')->fetchAll();
        $databaseFile = $databases[0]['file'] ?? '';
        $directory = $databaseFile !== '' ? dirname($databaseFile) : __DIR__ . '/../../data';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory . '/fints-bank-' . $bankId . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            return $this->failure('authorization_lock_unavailable', 503);
        }
        chmod($path, 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return $this->failure('authorization_busy', 409);
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function failure(string $reason, int $httpStatus = 409): array
    {
        return ['success' => false, 'reason' => $reason, 'http_status' => $httpStatus];
    }

    private function operation(int $bankId): ?array
    {
        $statement = $this->db->getPdo()->prepare('SELECT * FROM fints_authorization_operations WHERE bank_id = ?');
        $statement->execute([$bankId]);
        $operation = $statement->fetch();
        if (!$operation) {
            return null;
        }
        if (in_array($operation['status'], ['pending', 'running'], true) && (int) $operation['expires_at'] <= time()) {
            $this->finish($bankId, $this->failure('authorization_expired'), 'expired');
            $this->db->deleteFinTSSession($bankId);
            $this->db->setBankAuthorizationState($bankId, 'required', 'authorization_expired');
            $operation['status'] = 'expired';
            $operation['payload'] = null;
            $operation['response'] = json_encode($this->failure('authorization_expired'));
        }
        return $operation;
    }

    private function finish(int $bankId, array $response, string $status): void
    {
        $statement = $this->db->getPdo()->prepare('UPDATE fints_authorization_operations
            SET status = ?, payload = NULL, response = ? WHERE bank_id = ?');
        $statement->execute([$status, json_encode($response, JSON_THROW_ON_ERROR), $bankId]);
    }

    public function state(int $bankId, string $owner): array
    {
        return $this->locked($bankId, function () use ($bankId, $owner): array {
            if (!$this->db->getBankById($bankId)) {
                return $this->failure('bank_not_found', 404);
            }
            $operation = $this->operation($bankId);
            $state = $this->db->getBankAuthorizationState($bankId);
            $result = ['success' => true, 'authorization' => $state,
                'background_sync_available' => false, 'background_sync_reason' => FinTSService::BACKGROUND_BLOCK_REASON,
                'authorization_expiry_source' => $state['expires_at'] === null ? null : 'local_policy',
                'authorization_expiry_is_bank_guarantee' => false];
            if ($operation && $operation['status'] === 'pending' && hash_equals($operation['owner_hash'], hash('sha256', $owner))) {
                $pending = json_decode($operation['response'] ?? '{}', true);
                $result['operation_id'] = $operation['operation_id'];
                $result['tan_request'] = $pending['tan_request'] ?? null;
                $result['operation_expires_at'] = gmdate('c', (int) $operation['expires_at']);
            }
            return $result;
        });
    }

    public function blocked(int $bankId): array
    {
        return $this->locked($bankId, function () use ($bankId): array {
            if (!$this->db->getBankById($bankId)) {
                return $this->failure('bank_not_found', 404);
            }
            $this->operation($bankId);
            $state = $this->db->getBankAuthorizationState($bankId);
            if ($state['status'] === 'unknown') {
                $this->db->setBankAuthorizationState($bankId, 'required', FinTSService::BACKGROUND_BLOCK_REASON);
            }
            return FinTSService::backgroundBlocked() + ['authorization' => $this->db->getBankAuthorizationState($bankId)];
        });
    }

    public function authorize(int $bankId, string $owner, string $requestId): array
    {
        return $this->locked($bankId, function () use ($bankId, $owner, $requestId): array {
            $bank = $this->db->getBankById($bankId);
            if (!$bank) {
                return $this->failure('bank_not_found', 404);
            }
            $operation = $this->operation($bankId);
            if ($operation && (in_array($operation['status'], ['pending', 'running'], true) || $operation['request_id'] === $requestId)) {
                if (!hash_equals($operation['owner_hash'], hash('sha256', $owner))) {
                    return $this->failure('authorization_owned_by_other_browser');
                }
                return json_decode($operation['response'] ?? 'null', true) ?? $this->failure('authorization_busy');
            }
            $operation = ['bank_id' => $bankId, 'operation_id' => bin2hex(random_bytes(24)),
                'owner_hash' => hash('sha256', $owner), 'request_id' => $requestId, 'expires_at' => time() + 600];
            $statement = $this->db->getPdo()->prepare("INSERT OR REPLACE INTO fints_authorization_operations
                (bank_id, operation_id, owner_hash, request_id, status, expires_at) VALUES (?, ?, ?, ?, 'running', ?)");
            $statement->execute([$bankId, $operation['operation_id'], $operation['owner_hash'], $requestId, $operation['expires_at']]);
            $this->db->setBankAuthorizationState($bankId, 'pending', 'explicit_authorization_started');
            $session = $this->db->getFinTSSession($bankId);
            $this->db->deleteFinTSSession($bankId);
            return $this->run($bank, $operation, $session['session_data'] ?? null, null, null, false);
        });
    }

    public function resume(int $bankId, string $owner, string $operationId, ?string $tan, bool $poll): array
    {
        return $this->locked($bankId, function () use ($bankId, $owner, $operationId, $tan, $poll): array {
            $bank = $this->db->getBankById($bankId);
            $operation = $this->operation($bankId);
            if (!$bank || !$operation || !hash_equals($operation['operation_id'], $operationId)
                || !hash_equals($operation['owner_hash'], hash('sha256', $owner))) {
                return $this->failure('authorization_operation_mismatch');
            }
            if ($operation['status'] !== 'pending') {
                return json_decode($operation['response'] ?? 'null', true) ?? $this->failure('authorization_busy');
            }
            $response = json_decode($operation['response'], true);
            if ($poll !== !empty($response['tan_request']['is_decoupled'])) {
                return $this->failure('authorization_method_mismatch', 400);
            }
            if ($poll && time() < (int) $operation['next_poll_at']) {
                return $response;
            }
            $payload = unserialize(base64_decode($operation['payload'], true));
            $payload['poll_count'] = ($payload['poll_count'] ?? 0) + ($poll ? 1 : 0);
            $limit = (int) ($response['tan_request']['max_polls'] ?? 0);
            if ($poll && $limit > 0 && $payload['poll_count'] > $limit) {
                $this->finish($bankId, $this->failure('authorization_poll_limit'), 'error');
                $this->db->setBankAuthorizationState($bankId, 'required', 'authorization_poll_limit');
                return $this->failure('authorization_poll_limit');
            }
            // Persist consumption before any network request. A crash must not replay this action.
            $this->db->getPdo()->prepare("UPDATE fints_authorization_operations SET status = 'running', payload = NULL, response = NULL WHERE bank_id = ?")
                ->execute([$bankId]);
            return $this->run($bank, $operation, null, $payload, $tan, $poll);
        });
    }

    private function run(array $bank, array $operation, ?string $session, ?array $payload, ?string $tan, bool $poll): array
    {
        $bankId = (int) $bank['id'];
        $stats = $payload['stats'] ?? ['balances_updated' => 0, 'transactions_new' => 0,
            'transactions_updated' => 0, 'holdings_updated' => 0, 'errors' => []];
        try {
            $result = $this->fints->authorizeSync($bank, $this->db->getAccountsByBankId($bankId), $session,
                $payload['continuation'] ?? null, $tan, $poll, function (array $accounts) use ($bankId): array {
                    $existingAccounts = $this->db->getAccountsByBankId($bankId);
                    foreach ($accounts as $account) {
                        foreach ($existingAccounts as $existing) {
                            if ($existing['account_number'] === $account['account_number']
                                && ($existing['sub_account'] ?? '') === ($account['sub_account'] ?? '')) {
                                foreach (['account_name', 'owner_name', 'account_type', 'currency'] as $field) {
                                    if (isset($existing[$field])) {
                                        $account[$field] = $existing[$field];
                                    }
                                }
                                break;
                            }
                        }
                        $this->db->upsertAccount($bankId, $account);
                    }
                    return $this->db->getAccountsByBankId($bankId);
                });
            $results = $result['results'] ?? [];
            foreach ($results['balances'] ?? [] as $id => $balance) {
                $this->db->updateAccountBalance((int) $id, $balance['amount'], $balance['date'], $balance['currency'] ?? null);
                $stats['balances_updated']++;
            }
            foreach ($results['transactions'] ?? [] as $id => $transactions) {
                $saved = $this->db->saveTransactions((int) $id, $transactions);
                $stats['transactions_new'] += $saved['new'];
                $stats['transactions_updated'] += $saved['updated'];
            }
            foreach ($results['holdings'] ?? [] as $id => $holdings) {
                $stats['holdings_updated'] += $this->db->saveSecuritiesHoldings((int) $id, $holdings);
                $this->db->updateAccountBalance((int) $id, $this->db->getDepotTotalValue((int) $id) ?? 0, date('Y-m-d H:i:s'));
            }
            $stats['errors'] = array_merge($stats['errors'], $results['errors'] ?? []);
            $response = ['success' => (bool) $result['success'], 'operation_id' => $operation['operation_id'],
                'stats' => $stats, 'background_sync_available' => false];
            if (!empty($result['needs_tan'])) {
                $continuation = $result['continuation'];
                $sameAction = $payload !== null
                    && ($payload['continuation']['challenge_key'] ?? null) === ($continuation['challenge_key'] ?? null);
                if ($payload !== null && !$sameAction) {
                    // Bind each submission to the exact challenge, not merely the bank dialog.
                    $response['operation_id'] = bin2hex(random_bytes(24));
                }
                $continuation['context']['results'] = ['balances' => [], 'transactions' => [], 'holdings' => [], 'errors' => []];
                $response += ['needs_tan' => true, 'status' => 'pending', 'tan_request' => $result['tan_request'],
                    'operation_expires_at' => gmdate('c', (int) $operation['expires_at'])];
                $stored = base64_encode(serialize(['continuation' => $continuation, 'stats' => $stats,
                    'poll_count' => $sameAction ? ($payload['poll_count'] ?? 0) : 0]));
                $this->db->getPdo()->prepare("UPDATE fints_authorization_operations SET status = 'pending', payload = ?, response = ?, next_poll_at = ?, operation_id = ? WHERE bank_id = ?")
                    ->execute([$stored, json_encode($response, JSON_THROW_ON_ERROR), time() + ($result['tan_request']['poll_interval'] ?? 5), $response['operation_id'], $bankId]);
                $this->db->setBankAuthorizationState($bankId, 'pending', 'tan_confirmation_pending');
            } else {
                if ($result['success']) {
                    $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
                    $this->db->setBankAuthorizationState($bankId, 'authorized');
                    $response['partial'] = !empty($stats['errors']);
                } else {
                    $response['reason'] = $result['reason'] ?? 'fints_operation_failed';
                    $this->db->setBankAuthorizationState($bankId, 'error', $response['reason']);
                }
                $this->finish($bankId, $response, $result['success'] ? 'completed' : 'error');
            }
            $response['authorization'] = $this->db->getBankAuthorizationState($bankId);
            return $response;
        } catch (\Throwable $e) {
            $response = $this->failure('authorization_operation_failed', 500);
            $response['stats'] = $stats;
            $response['partial'] = $stats['balances_updated'] > 0 || $stats['transactions_new'] > 0 || $stats['holdings_updated'] > 0;
            $this->finish($bankId, $response, 'error');
            $this->db->deleteFinTSSession($bankId);
            $this->db->setBankAuthorizationState($bankId, 'error', 'authorization_operation_failed');
            return $response;
        }
    }

    public function cancel(int $bankId, string $owner, string $operationId, bool $reset = false): array
    {
        return $this->locked($bankId, function () use ($bankId, $owner, $operationId, $reset): array {
            if (!$this->db->getBankById($bankId)) {
                return $this->failure('bank_not_found', 404);
            }
            $operation = $this->operation($bankId);
            if ($operation && in_array($operation['status'], ['pending', 'running'], true)
                && (!hash_equals($operation['owner_hash'], hash('sha256', $owner))
                    || (!$reset && !hash_equals($operation['operation_id'], $operationId)))) {
                return $this->failure('authorization_operation_mismatch');
            }
            if (!$reset && (!$operation || !hash_equals($operation['operation_id'], $operationId)
                || !hash_equals($operation['owner_hash'], hash('sha256', $owner)))) {
                return $this->failure('authorization_operation_mismatch');
            }
            // Local invalidation only: cancellation must not open a bank dialog.
            $this->finish($bankId, $this->failure('authorization_cancelled'), 'cancelled');
            $this->db->deleteFinTSSession($bankId);
            $this->db->setBankAuthorizationState($bankId, 'required', $reset ? 'session_reset' : 'authorization_cancelled');
            return ['success' => true, 'authorization' => $this->db->getBankAuthorizationState($bankId)];
        });
    }
}
