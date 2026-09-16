<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Services/DatabaseService.php';

use App\Services\DatabaseService;

$checks = 0;
function checkDatabase(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function expectDatabaseFailure(callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $e) {
        checkDatabase(true, 'Expected failure');
        return;
    }
    throw new RuntimeException('Expected operation to fail');
}

function bankFixture(DatabaseService $db, string $name): int
{
    return $db->createBank([
        'name' => $name, 'bank_code' => '10000000', 'fints_url' => 'https://bank.invalid',
        'username' => 'fixture', 'password' => 'fixture',
    ]);
}

function legacyTransaction(DatabaseService $db, int $account, array $data): int
{
    $data += ['transaction_id' => bin2hex(random_bytes(16)), 'booking_date' => '2026-09-01',
        'valuta_date' => '2026-09-01', 'amount' => -10, 'currency' => 'EUR',
        'name' => 'Merchant', 'description' => 'Payment'];
    $columns = array_keys($data);
    $stmt = $db->getPdo()->prepare('INSERT INTO transactions (account_id, ' . implode(', ', $columns)
        . ') VALUES (?, ' . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $stmt->execute(array_merge([$account], array_values($data)));
    return (int) $db->getPdo()->lastInsertId();
}

$directory = sys_get_temp_dir() . '/banking-database-integrity-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Unable to create isolated database fixture directory');
}
$path = $directory . '/test.sqlite';
$legacyPath = $path . '.legacy';
try {
    $db = new DatabaseService($path);
    $pdo = $db->getPdo();
    checkDatabase((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'Foreign keys enabled');
    checkDatabase((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn() === 5000, 'Busy timeout enabled');
    $bank = bankFixture($db, 'Bank');
    $account = $db->upsertAccount($bank, ['account_number' => '1', 'balance' => 42.5,
        'balance_date' => '2026-08-31 10:00:00', 'currency' => 'USD']);
    $db->upsertAccount($bank, ['account_number' => '1']);
    $saved = $db->getAccountById($account);
    checkDatabase((float) $saved['balance'] === 42.5 && $saved['balance_date'] === '2026-08-31 10:00:00'
        && $saved['currency'] === 'USD', 'Missing balances and currency preserve good data');
    $db->upsertAccount($bank, ['account_number' => '1', 'balance' => 0]);
    checkDatabase((float) $db->getAccountById($account)['balance'] === 0.0, 'Explicit zero balance saved');
    $db->updateAccountBalance($account, 100, '2026-09-01 10:00:00', 'SEK');
    checkDatabase($db->getAccountById($account)['currency'] === 'SEK'
        && (float) $db->getAccountById($account)['balance'] === 100.0, 'Successful saldo stores actual bank currency');
    $db->updateAccountBalance($account, 110);
    checkDatabase($db->getAccountById($account)['currency'] === 'SEK', 'Legacy balance updater preserves known currency');
    $db->upsertAccount($bank, ['account_number' => '1', 'account_name' => 'Discovered account']);
    checkDatabase($db->getAccountById($account)['currency'] === 'SEK', 'Metadata-only discovery does not relabel foreign currency');

    $base = ['booking_date' => '2026-09-01', 'valuta_date' => '2026-09-01',
        'amount' => -10, 'currency' => 'EUR', 'name' => 'Merchant', 'description' => 'Payment'];
    $strong = [
        $base + ['prima_nota' => 'A', 'end_to_end_id' => 'RECURRING'],
        $base + ['prima_nota' => 'B', 'end_to_end_id' => 'RECURRING'],
        $base + ['end_to_end_id' => 'RECURRING'],
        array_replace($base, ['end_to_end_id' => 'RECURRING', 'booking_date' => '2026-09-02']),
        array_replace($base, ['end_to_end_id' => 'RECURRING', 'amount' => -11]),
        array_replace($base, ['end_to_end_id' => 'RECURRING', 'currency' => 'USD']),
        $base + ['transaction_id' => 'BANK-1'],
        $base + ['transaction_id' => 'BANK-2'],
    ];
    checkDatabase($db->saveTransactions($account, $strong)['new'] === 8, 'Different and recurring references preserved');
    checkDatabase($db->saveTransactions($account, array_reverse($strong))['new'] === 0, 'Strong reference replay idempotent');
    checkDatabase($db->saveTransactions($account, $strong) === ['new' => 0, 'updated' => 0, 'skipped' => 8, 'total' => 8],
        'Duplicate replays count as skipped, never as updates');
    checkDatabase($db->saveTransactions($account, []) === ['new' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0],
        'Empty import reports zero counters');
    checkDatabase($db->saveTransaction($account, array_replace($strong[0], ['description' => 'Enriched text'])) === 0,
        'Strong references take precedence over changed descriptive text');
    checkDatabase($db->saveTransactions($account, [$strong[0], $strong[0]])['new'] === 0, 'Repeated strong reference is one payment');

    $fallback = [$base, $base, array_replace($base, ['description' => 'Other payment']),
        array_replace($base, ['currency' => 'USD']), array_replace($base, ['name' => str_repeat('x', 51) . 'A']),
        array_replace($base, ['name' => str_repeat('x', 51) . 'B']),
        array_replace($base, ['amount' => -10.001]), array_replace($base, ['amount' => -10.002])];
    checkDatabase($db->saveTransactions($account, $fallback)['new'] === 8, 'Full semantics and identical fallback occurrences preserved');
    checkDatabase($db->saveTransactions($account, array_reverse($fallback))['new'] === 0, 'Fallback reordered batch replay idempotent');
    checkDatabase($db->saveTransactions($account, [$base, $base, $base])['new'] === 1, 'Additional identical fallback occurrence saved');
    checkDatabase($db->saveTransactions($account, [$base])['new'] === 0, 'Smaller replay does not remove occurrences');
    checkDatabase($db->saveTransaction($account, $base + ['end_to_end_id' => ' notprovided ']) === 0,
        'Placeholder reference treated as absent');
    $before = $db->getTransactionCount($account);
    expectDatabaseFailure(fn() => $db->saveTransactions($account, [array_replace($base, ['description' => 'Rollback']), ['amount' => null]]));
    checkDatabase($db->getTransactionCount($account) === $before, 'Transaction batch rolls back on invalid data');

    $legacyAccount = $db->upsertAccount($bank, ['account_number' => 'legacy']);
    legacyTransaction($db, $legacyAccount, $base);
    checkDatabase($db->saveTransactions($legacyAccount, [$base, $base])['new'] === 1, 'Legacy exact fallback match reused with occurrence count');
    $legacyStrong = $base + ['end_to_end_id' => 'legacy-ref'];
    legacyTransaction($db, $legacyAccount, $legacyStrong);
    checkDatabase($db->saveTransaction($legacyAccount, $legacyStrong) === 0, 'Legacy strong-reference match reused');
    $adopt = array_replace($base, ['description' => 'Lost bank ID']);
    legacyTransaction($db, $legacyAccount, $adopt);
    checkDatabase($db->saveTransaction($legacyAccount, $adopt + ['transaction_id' => 'restored-bank-id']) === 0,
        'Legacy discarded bank ID adopted through exact semantic match');
    checkDatabase($db->saveTransaction($legacyAccount, $adopt + ['transaction_id' => 'different-bank-id']) === 1,
        'Adopted legacy row cannot swallow another bank reference');
    checkDatabase($db->saveTransaction($legacyAccount, $adopt + ['transaction_id' => 'restored-bank-id']) === 0,
        'Adopted source reference remains idempotent');
    $metadataAdoption = array_replace($base, ['description' => 'Adoption statistics']);
    legacyTransaction($db, $legacyAccount, $metadataAdoption);
    checkDatabase($db->saveTransactions($legacyAccount, [$metadataAdoption + ['transaction_id' => 'metadata-bank-id']])
        === ['new' => 0, 'updated' => 1, 'skipped' => 0, 'total' => 1],
        'Actual legacy source-ID metadata adoption counts as an update');
    checkDatabase($db->saveTransactions($legacyAccount, [$metadataAdoption + ['transaction_id' => 'metadata-bank-id']])
        === ['new' => 0, 'updated' => 0, 'skipped' => 1, 'total' => 1],
        'Adopted metadata replay counts as skipped');
    checkDatabase($db->saveTransaction($legacyAccount, array_replace($base, ['description' => 'Different legacy payment'])) === 1,
        'Legacy coarse similarity does not discard new payments');
    $afterRegeneration = array_replace($base, ['description' => 'Legacy source after regeneration']);
    legacyTransaction($db, $legacyAccount, $afterRegeneration);
    $db->regenerateTransactionIds($legacyAccount);
    checkDatabase($db->saveTransaction($legacyAccount, $afterRegeneration + ['transaction_id' => 'later-bank-id']) === 0,
        'Regeneration preserves legacy provenance for exact source-ID adoption');

    $collisionAccount = $db->upsertAccount($bank, ['account_number' => 'collisions']);
    $db->saveTransaction($collisionAccount, $base);
    $targetId = $pdo->query("SELECT transaction_id FROM transactions WHERE account_id = {$collisionAccount}")->fetchColumn();
    $pdo->exec("UPDATE transactions SET description = 'Other payment' WHERE account_id = {$collisionAccount}");
    checkDatabase($db->saveTransaction($collisionAccount, $base) === 1, 'Occupied local hash does not drop unrelated transaction');
    checkDatabase($db->saveTransaction($collisionAccount, $base) === 0, 'Collision-suffixed transaction replay idempotent');
    $snapshot = $pdo->query("SELECT * FROM transactions WHERE account_id = {$collisionAccount} ORDER BY id")->fetchAll();
    $pdo->exec("CREATE TRIGGER reject_regeneration BEFORE UPDATE OF transaction_id ON transactions
        WHEN NEW.transaction_id LIKE 'v2:%' AND NEW.account_id = {$collisionAccount}
        BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    expectDatabaseFailure(fn() => $db->regenerateTransactionIds($collisionAccount));
    checkDatabase($pdo->query("SELECT * FROM transactions WHERE account_id = {$collisionAccount} ORDER BY id")->fetchAll() === $snapshot,
        'Failed ID regeneration restores all original IDs');
    $pdo->exec('DROP TRIGGER reject_regeneration');
    checkDatabase($db->regenerateTransactionIds($collisionAccount) === 2, 'ID regeneration handles occupied target hashes');
    checkDatabase($db->saveTransactions($collisionAccount, [$base, array_replace($base, ['description' => 'Other payment'])])['new'] === 0,
        'Regenerated collision rows remain idempotent');

    $maintenance = $db->upsertAccount($bank, ['account_number' => 'maintenance']);
    legacyTransaction($db, $maintenance, $base);
    legacyTransaction($db, $maintenance, $base);
    legacyTransaction($db, $maintenance, $strong[0]);
    legacyTransaction($db, $maintenance, $strong[0]);
    legacyTransaction($db, $maintenance, $strong[0]);
    legacyTransaction($db, $maintenance, $strong[1]);
    legacyTransaction($db, $maintenance, array_replace($strong[0], ['description' => 'Conflicting detail']));
    checkDatabase(count($db->findDuplicateTransactions($maintenance)) === 3, 'Maintenance ignores ambiguous and conflicting payments');
    checkDatabase($db->getDuplicateSummary($maintenance)['total_to_remove'] === 2, 'Summary uses same conservative identity');
    $pdo->exec("CREATE TRIGGER reject_deletion BEFORE DELETE ON transactions
        WHEN OLD.account_id = {$maintenance} AND (SELECT COUNT(*) FROM transactions WHERE account_id = {$maintenance}) = 6
        BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    expectDatabaseFailure(fn() => $db->removeDuplicateTransactions($maintenance));
    checkDatabase($db->getTransactionCount($maintenance) === 7, 'Duplicate removal rolls back earlier deletes');
    $pdo->exec('DROP TRIGGER reject_deletion');
    checkDatabase($db->regenerateTransactionIds($maintenance) === 7 && $db->getTransactionCount($maintenance) === 7,
        'Regeneration preserves ambiguous and duplicate rows without unique collisions');
    checkDatabase($db->removeDuplicateTransactions($maintenance) === 2 && $db->getTransactionCount($maintenance) === 5,
        'Only proven strong-reference duplicate rows removed');
    checkDatabase($db->saveTransactions($maintenance, [$base, $base])['new'] === 0, 'Reference-less occurrences survive maintenance');
    checkDatabase($db->removeDuplicateTransactions($maintenance) === 0, 'Maintenance is idempotent');

    $holding = ['name' => 'Security', 'quantity' => 2, 'total_value' => 80];
    checkDatabase($db->saveSecuritiesHoldings($account, [$holding]) === 1, 'Holding saved');
    expectDatabaseFailure(fn() => $db->saveSecuritiesHoldings($account, [$holding, ['name' => null, 'quantity' => 1]]));
    checkDatabase(count($db->getSecuritiesHoldings($account)) === 1, 'Failed holdings replacement preserves prior snapshot');
    checkDatabase($db->saveSecuritiesHoldings($account, []) === 0 && $db->getSecuritiesHoldings($account) === [],
        'Successful empty holdings response clears snapshot');
    $pdo->beginTransaction();
    $db->saveSecuritiesHoldings($account, [$holding]);
    $pdo->rollBack();
    checkDatabase($db->getSecuritiesHoldings($account) === [], 'Nested financial operation respects caller rollback');

    $db->setAccountMqttExport($account, true);
    $db->setAccountTanManualApproval($account, true);
    $db->setAccountExcludeFromTotal($account, true);
    checkDatabase(in_array($account, array_column($db->getAccountsForBackgroundSync($bank), 'id'), true),
        'Background sync independent of MQTT, TAN policy and totals');
    checkDatabase(!$db->setAccountBackgroundSync(999999, false), 'Missing account background toggle returns false for API 404');
    checkDatabase($db->setAccountBackgroundSync($account, false), 'Existing account background toggle returns true');
    checkDatabase($db->setAccountBackgroundSync($account, false), 'Unchanged existing account background toggle still returns true');
    checkDatabase(!in_array($account, array_column($db->getAccountsForBackgroundSync($bank), 'id'), true),
        'Background opt-out is respected');
    $db->setAccountBackgroundSync($account, true);
    $pdo->exec("UPDATE accounts SET is_active = 0 WHERE id = {$account}");
    checkDatabase(!in_array($account, array_column($db->getAccountsForBackgroundSync($bank), 'id'), true),
        'Inactive accounts excluded from background sync');
    checkDatabase($db->getBankAuthorizationState($bank)['status'] === 'unknown', 'Default authorization unknown');
    $db->setBankAuthorizationState($bank, 'authorized');
    $authorizedAt = $db->getBankAuthorizationState($bank)['authenticated_at'];
    checkDatabase(str_ends_with($authorizedAt, 'Z'), 'Authorization timestamps in UTC');
    $db->setBankAuthorizationState($bank, 'required', 'expired');
    $pdo->exec("UPDATE bank_authorization SET required_at = '2026-01-01T00:00:00Z' WHERE bank_id = {$bank}");
    $db->setBankAuthorizationState($bank, 'required', 'expired');
    checkDatabase($db->getBankAuthorizationState($bank)['required_at'] === '2026-01-01T00:00:00Z', 'Repeated required state preserves first occurrence');
    $db->setBankAuthorizationState($bank, 'authorized');
    checkDatabase($db->getBankAuthorizationState($bank)['required_at'] === null, 'Authorization clears required timestamp');
    expectDatabaseFailure(fn() => $db->setBankAuthorizationState($bank, 'invented'));
    expectDatabaseFailure(fn() => $db->setBankAuthorizationState($bank, 'error', 'Unsafe reason'));

    $sessionId = $db->saveFinTSSession($bank, "binary\0data", 'mode', 'medium');
    $session = $db->getFinTSSession($bank);
    $db->saveFinTSSession($bank, 'updated');
    checkDatabase($db->getFinTSSession($bank)['expires_at'] === $session['expires_at']
        && $db->getFinTSSession($bank)['tan_mode'] === 'mode', 'Session refresh preserves authorization window and TAN selection');
    checkDatabase($session['session_data'] === "binary\0data", 'Binary FinTS sessions round-trip');
    $pdo->exec("UPDATE fints_sessions SET expires_at = '2000-01-01' WHERE id = {$sessionId}");
    checkDatabase($db->getFinTSSession($bank) === null && $db->getFinTSSession($bank, true)['session_data'] === 'updated',
        'Expired session retrieval requires explicit opt-in');

    $otherBank = bankFixture($db, 'Other');
    $otherAccount = $db->upsertAccount($otherBank, ['account_number' => 'other']);
    $db->linkAccountToDepot($otherAccount, $account);
    $db->saveSecuritiesHoldings($account, [$holding]);
    $db->saveBankCapabilities($bank, []);
    $db->logActivity('sync', 'success', null, $bank, $account);
    $pdo->exec("CREATE TRIGGER reject_bank_delete BEFORE DELETE ON banks
        WHEN OLD.id = {$bank} BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    expectDatabaseFailure(fn() => $db->deleteBank($bank));
    checkDatabase((int) $db->getAccountById($otherAccount)['linked_depot_id'] === $account,
        'Failed bank deletion restores cross-bank links');
    $pdo->exec('DROP TRIGGER reject_bank_delete');
    $db->deleteBank($bank);
    checkDatabase($db->getAccountById($otherAccount)['linked_depot_id'] === null, 'Cross-bank depot link cleared on bank deletion');
    foreach (['accounts' => 'bank_id', 'bank_authorization' => 'bank_id', 'bank_capabilities' => 'bank_id',
        'fints_sessions' => 'bank_id', 'activity_log' => 'bank_id', 'transactions' => 'account_id',
        'securities_holdings' => 'account_id'] as $table => $column) {
        $id = $column === 'bank_id' ? $bank : $account;
        checkDatabase((int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$column} = {$id}")->fetchColumn() === 0,
            "{$table} cascades");
    }
    checkDatabase($pdo->query('PRAGMA foreign_key_check')->fetchAll() === [], 'Fresh database maintains all foreign keys');

    $legacy = new PDO('sqlite:' . $legacyPath);
    $legacy->exec("CREATE TABLE banks (id INTEGER PRIMARY KEY, name TEXT NOT NULL, bank_code TEXT NOT NULL,
        fints_url TEXT NOT NULL, username TEXT NOT NULL, password TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $legacy->exec("CREATE TABLE accounts (id INTEGER PRIMARY KEY, bank_id INTEGER NOT NULL, account_number TEXT NOT NULL,
        iban TEXT, bic TEXT, account_name TEXT, owner_name TEXT, currency TEXT DEFAULT 'EUR',
        balance REAL, balance_date DATETIME, is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE)");
    $legacy->exec("INSERT INTO accounts (id, bank_id, account_number, balance) VALUES (1, 999, 'orphan', 123)");
    $legacy->exec("CREATE TABLE transactions (id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL, transaction_id TEXT,
        booking_date DATE, valuta_date DATE, amount REAL NOT NULL, currency TEXT DEFAULT 'EUR',
        name TEXT, description TEXT, iban TEXT, bic TEXT, mandate_id TEXT, creditor_id TEXT,
        end_to_end_id TEXT, booking_text TEXT, prima_nota TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE, UNIQUE(account_id, transaction_id))");
    $legacy->exec("INSERT INTO transactions (account_id, transaction_id, booking_date, valuta_date, amount, name, description)
        VALUES (1, 'old-local-hash', '2026-09-01', '2026-09-01', -10, 'Merchant', 'Payment')");
    $legacy = null;
    $migrated = new DatabaseService($legacyPath);
    checkDatabase((float) $migrated->getAccountById(1)['balance'] === 123.0, 'Legacy orphan preserved without automatic cleanup');
    checkDatabase((int) $migrated->getAccountById(1)['background_sync_enabled'] === 1, 'Legacy schema gains independent background default');
    checkDatabase(count($migrated->getPdo()->query('PRAGMA foreign_key_check')->fetchAll()) === 1, 'Legacy orphan remains detectable');
    checkDatabase($migrated->saveTransaction(1, $base) === 0, 'Pre-migration transactions remain idempotent after adding source ID');
    checkDatabase($migrated->saveTransaction(1, $base + ['transaction_id' => 'original-bank-id']) === 0,
        'Source-ID migration permits safe exact adoption without rewriting old hashes');
    $migratedBank = bankFixture($migrated, 'Migrated');
    $migratedAccount = $migrated->upsertAccount($migratedBank, ['account_number' => 'new']);
    $migrated->saveTransaction($migratedAccount, $base);
    $migrated->deleteBank($migratedBank);
    checkDatabase($migrated->getTransactionCount($migratedAccount) === 0, 'Legacy database retains functioning cascades');
    $migrated = new DatabaseService($legacyPath);
    checkDatabase($migrated->getAccountById(1) !== null, 'Migrations repeat safely without orphan cleanup');
    echo "database_integrity: {$checks} checks passed\n";
} finally {
    unset($migrated, $legacy, $db, $pdo);
    foreach (glob($directory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
}
