#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\AuthorizationService;
use App\Services\DatabaseService;
use App\Services\FinTSService;
use App\Services\MqttService;
use App\Services\PayPalService;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$logger = new Logger('auto-sync');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
$logger->info('=== AUTO SYNC CRON STARTED ===');
$dataPath = getenv('DATA_PATH') ?: '/data';
$dbPath = $dataPath . '/banking.db';
if (!file_exists($dbPath)) {
    $logger->info('Database not found, skipping sync');
    exit(0);
}
$lock = fopen($dataPath . '/auto-sync.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $logger->info('Another auto-sync is running, skipping');
    exit(0);
}
$db = null;
try {
    $db = new DatabaseService($dbPath);
    if ($db->getSetting('auto_sync_enabled', '0') !== '1') {
        $logger->info('Auto-sync is disabled');
        exit(0);
    }
    $interval = max(1, (int) $db->getSetting('auto_sync_interval', '30')) * 60;
    if (time() - (int) $db->getSetting('auto_sync_last_run_timestamp', '0') < $interval) {
        $logger->info('Not yet time for sync');
        exit(0);
    }

    // phpFinTS cannot prohibit a challenge before login/execute sends HKTAN.
    // Do not instantiate a bank dialog, even with a recently cached session.
    $authorization = new AuthorizationService($db, new FinTSService($logger));
    $stats = ['banks_synced' => 0, 'banks_skipped' => 0, 'paypal_synced' => 0, 'paypal_partial' => 0,
        'balances_updated' => 0, 'transactions_new' => 0, 'holdings_updated' => 0, 'errors' => []];
    foreach ($db->getAllBanks() as $bank) {
        $id = (int) $bank['id'];
        if (empty($db->getAccountsForBackgroundSync($id))) {
            $reason = 'no_background_accounts';
        } else {
            $result = $authorization->blocked($id);
            $reason = $result['reason'] ?? FinTSService::BACKGROUND_BLOCK_REASON;
            $stats['errors'][] = $bank['name'] . ': ' . $reason;
        }
        $stats['banks_skipped']++;
        $db->logActivity('auto_sync_skipped', 'warning', $reason, $id);
        $logger->info('FinTS sync skipped before network access', ['bank_id' => $id, 'reason' => $reason]);
    }

    // PayPal is independent of FinTS banks and the FinTS product registration.
    $paypal = new PayPalService($logger, $db);
    foreach ($db->getAllPayPalAccounts() as $account) {
        try {
            $result = $paypal->syncAccount((int) $account['id']);
            $stats['balances_updated'] += isset($result['balance']) ? 1 : 0;
            $stats['transactions_new'] += (int) ($result['transactions_new'] ?? 0);
            if (empty($result['success'])) {
                $stats['paypal_partial'] += !empty($result['partial']) ? 1 : 0;
                $stats['errors'][] = 'PayPal: ' . $account['name'];
                continue;
            }
            $stats['paypal_synced']++;
        } catch (\Throwable $e) {
            $stats['errors'][] = 'PayPal: ' . $account['name'];
            $logger->warning('PayPal sync failed', ['account_id' => $account['id']]);
        }
    }
    $db->setSetting('auto_sync_last_run', date('d.m.Y H:i'));
    $db->setSetting('auto_sync_last_run_timestamp', (string) time());
    $db->setSetting('auto_sync_last_status', empty($stats['errors']) ? 'success' : 'partial');
    $db->setSetting('auto_sync_last_error', implode(', ', $stats['errors']));
    $mqtt = new MqttService($logger, $db);
    if ($mqtt->isEnabled()) {
        $logger->info('MQTT publish result', $mqtt->publishAccountBalances());
    }
    $logger->info('=== AUTO SYNC COMPLETED ===', $stats);
} catch (\Throwable $e) {
    $logger->error('Auto-sync failed', ['exception' => get_class($e)]);
    if ($db !== null) {
        $db->setSetting('auto_sync_last_status', 'error');
        $db->setSetting('auto_sync_last_error', 'auto_sync_failed');
    }
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
