<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Services/DatabaseService.php';

use App\Services\DatabaseService;

$checks = 0;
function checkAuthorization(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$directory = sys_get_temp_dir() . '/banking-database-authorization-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Unable to create isolated authorization fixture directory');
}
$path = $directory . '/test.sqlite';
$timezone = date_default_timezone_get();
try {
    date_default_timezone_set('Pacific/Auckland');
    $db = new DatabaseService($path);
    $pdo = $db->getPdo();
    $fixture = ['name' => 'Fixture', 'bank_code' => '10000000', 'fints_url' => 'https://bank.invalid',
        'username' => 'fixture', 'password' => 'fixture'];
    $bank = $db->createBank($fixture);
    $pendingBank = $db->createBank($fixture);
    $db->saveFinTSSession($bank, 'technical session');
    $technicalSession = $db->getFinTSSession($bank);
    checkAuthorization($db->getBankAuthorizationState($bank)['status'] === 'unknown',
        'A technical session does not imply explicit authorization');
    $db->setBankAuthorizationState($pendingBank, 'pending', 'tan_required');
    $pending = $db->getBankAuthorizationState($pendingBank);
    checkAuthorization($pending['authenticated_at'] === null && $pending['expires_at'] === null,
        'Pending initial authorization does not establish authorization dates');
    $db->setBankAuthorizationState($bank, 'authorized');
    $authorized = $db->getBankAuthorizationState($bank);
    checkAuthorization($authorized['status'] === 'authorized' && str_ends_with($authorized['expires_at'], 'Z')
        && str_ends_with($authorized['authenticated_at'], 'Z'), 'Completed authorization writes UTC dates');
    checkAuthorization(strtotime($authorized['expires_at']) - strtotime($authorized['authenticated_at']) === 90 * 86400,
        'Default local maximum age is 90 UTC days');
    checkAuthorization(abs(strtotime($authorized['authenticated_at']) - time()) <= 2,
        'Authorization date is actual UTC time despite process timezone');

    foreach (['1' => 1, '365' => 365, '0' => 1, '-50' => 1, '9999' => 365, 'invalid' => 90, '2.5' => 90] as $setting => $days) {
        $db->setSetting('fints_authorization_max_age_days', (string) $setting);
        $db->setBankAuthorizationState($bank, 'authorized');
        $state = $db->getBankAuthorizationState($bank);
        checkAuthorization(strtotime($state['expires_at']) - strtotime($state['authenticated_at']) === $days * 86400,
            "Local maximum age setting {$setting} uses bounded/default value");
    }
    $db->setSetting('fints_authorization_max_age_days', '7');
    $pdo->exec("UPDATE bank_authorization SET authenticated_at = '2000-01-01T00:00:00Z',
        expires_at = '2099-01-01T00:00:00Z' WHERE bank_id = {$bank}");
    $db->setBankAuthorizationState($bank, 'authorized');
    $refreshed = $db->getBankAuthorizationState($bank);
    checkAuthorization($refreshed['authenticated_at'] !== '2000-01-01T00:00:00Z'
        && strtotime($refreshed['expires_at']) - strtotime($refreshed['authenticated_at']) === 7 * 86400,
        'Repeated completed explicit authorization renews dates even without state or reason change');
    $db->setBankAuthorizationState($bank, 'pending', 'tan_required');
    $pending = $db->getBankAuthorizationState($bank);
    checkAuthorization($pending['authenticated_at'] === $refreshed['authenticated_at']
        && $pending['expires_at'] === $refreshed['expires_at'], 'Pending challenge does not renew existing dates');
    $db->setBankAuthorizationState($bank, 'error', 'authorization_failed');
    $failed = $db->getBankAuthorizationState($bank);
    checkAuthorization($failed['authenticated_at'] === $refreshed['authenticated_at']
        && $failed['expires_at'] === $refreshed['expires_at'], 'Failed authorization does not renew existing dates');

    $db->setBankAuthorizationState($bank, 'authorized');
    $authenticatedAt = $db->getBankAuthorizationState($bank)['authenticated_at'];
    $expiredAt = gmdate('Y-m-d\TH:i:s\Z');
    $stmt = $pdo->prepare('UPDATE bank_authorization SET expires_at = ? WHERE bank_id = ?');
    $stmt->execute([$expiredAt, $bank]);
    $expired = $db->getBankAuthorizationState($bank);
    checkAuthorization($expired['status'] === 'required' && $expired['reason'] === 'local_authorization_expired',
        'Deadline equal to now transitions authoritative state locally');
    checkAuthorization($expired['authenticated_at'] === $authenticatedAt && $expired['expires_at'] === $expiredAt,
        'Expiry preserves authentication history and deadline');
    checkAuthorization($expired['required_at'] !== null && $expired['updated_at'] === $expired['required_at'],
        'Local expiry records the required timestamp');
    checkAuthorization($db->getBankAuthorizationState($bank) === $expired, 'Repeated expiry reads do not churn timestamps');
    checkAuthorization($pdo->query("SELECT status FROM bank_authorization WHERE bank_id = {$bank}")->fetchColumn() === 'required',
        'Expiry changes persistent state, not only the returned snapshot');
    checkAuthorization($db->getFinTSSession($bank) === $technicalSession, 'Local authorization expiry does not alter technical session');

    $db->setBankAuthorizationState($bank, 'authorized');
    $pdo->exec("UPDATE bank_authorization SET authenticated_at = '2000-01-01T00:00:00Z', expires_at = NULL
        WHERE bank_id = {$bank}");
    $legacy = $db->getBankAuthorizationState($bank);
    checkAuthorization($legacy['status'] === 'required' && $legacy['expires_at'] === '2000-01-08T00:00:00Z'
        && $legacy['authenticated_at'] === '2000-01-01T00:00:00Z',
        'Legacy null deadline derives from original authentication, never from current time');
    $db->setBankAuthorizationState($bank, 'authorized');
    $before = $db->getBankAuthorizationState($bank);
    $pdo->exec("UPDATE bank_authorization SET expires_at = NULL WHERE bank_id = {$bank}");
    checkAuthorization($db->getBankAuthorizationState($bank) === $before,
        'Recent legacy authorization receives the correct deadline without resetting history');
    $pdo->exec("UPDATE bank_authorization SET authenticated_at = NULL, expires_at = NULL WHERE bank_id = {$bank}");
    checkAuthorization($db->getBankAuthorizationState($bank)['status'] === 'required',
        'Legacy authorized state without authentication time fails closed');
    $db->setBankAuthorizationState($bank, 'authorized');
    $pdo->exec("UPDATE bank_authorization SET expires_at = 'invalid' WHERE bank_id = {$bank}");
    checkAuthorization($db->getBankAuthorizationState($bank)['status'] === 'required',
        'Malformed local deadline cannot grant unbounded authorization');

    $db->setBankAuthorizationState($bank, 'authorized');
    $pdo->exec("UPDATE bank_authorization SET expires_at = '2000-01-01T00:00:00Z' WHERE bank_id = {$bank}");
    $db->setBankAuthorizationState($bank, 'pending', 'tan_required');
    checkAuthorization($db->getBankAuthorizationState($bank)['status'] === 'pending',
        'Expiry read does not overwrite an active explicit pending challenge');
    $db->setBankAuthorizationState($bank, 'authorized');
    $pdo->exec("UPDATE bank_authorization SET expires_at = '2000-01-01T00:00:00Z' WHERE bank_id = {$bank}");
    $pdo->beginTransaction();
    checkAuthorization($db->getBankAuthorizationState($bank)['status'] === 'required',
        'Expiry works inside an existing database transaction');
    $pdo->rollBack();
    checkAuthorization($pdo->query("SELECT status FROM bank_authorization WHERE bank_id = {$bank}")->fetchColumn() === 'authorized',
        'Atomic expiry transition respects outer rollback');
    $pdo->exec("CREATE TRIGGER reject_expiry BEFORE UPDATE OF status ON bank_authorization
        WHEN NEW.reason = 'local_authorization_expired' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    $pdo->exec("UPDATE bank_authorization SET authenticated_at = '2000-01-01T00:00:00Z', expires_at = NULL
        WHERE bank_id = {$bank}");
    try {
        $db->getBankAuthorizationState($bank);
        throw new RuntimeException('Expected expiry failure');
    } catch (PDOException $e) {
        checkAuthorization($pdo->query("SELECT expires_at FROM bank_authorization WHERE bank_id = {$bank}")->fetchColumn() === null,
            'Failed expiry transition rolls back legacy deadline backfill too');
    }
    echo "database_authorization: {$checks} checks passed\n";
} finally {
    date_default_timezone_set($timezone);
    unset($db, $pdo, $stmt);
    foreach (glob($directory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
}
