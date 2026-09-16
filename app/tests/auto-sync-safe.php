<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\DatabaseService;

$directory = __DIR__ . '/.auto-sync-test-' . getmypid();
mkdir($directory, 0700);
try {
    $db = new DatabaseService($directory . '/banking.db');
    $db->setSetting('auto_sync_enabled', '1');
    $db->setSetting('auto_sync_interval', '1');
    $db->createPayPalAccount(['name' => 'Offline PayPal', 'email' => 'fixture@example.invalid',
        'api_username' => 'fixture', 'api_password' => 'fixture', 'api_signature' => 'fixture', 'currency' => 'EUR']);
    $run = function (bool $api = false) use ($directory): string {
        $command = 'FINTS_OFFLINE_TEST=1 FINTS_API_OFFLINE_TEST=' . ($api ? '1' : '0') . ' DATA_PATH=' . escapeshellarg($directory) . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/auto-sync-offline-fixture.php');
        exec($command . ' 2>&1', $output, $exit);
        if ($exit !== 0) {
            throw new RuntimeException(implode("\n", $output));
        }
        return implode("\n", $output);
    };
    $output = $run();
    if ($db->getSetting('offline_paypal_calls') !== '1' || $db->getSetting('auto_sync_last_status') !== 'success') {
        throw new RuntimeException('PayPal-only cron failed without FinTS bank/product ID: ' . $output);
    }
    if (!str_contains($output, '"paypal_synced":1') || !str_contains($output, '"transactions_new":2')) {
        throw new RuntimeException('PayPal cron statistics inaccurate: ' . $output);
    }
    $bank = $db->createBank(['name' => 'Offline bank', 'bank_code' => '00000000',
        'fints_url' => 'https://invalid.invalid', 'username' => 'fixture', 'password' => 'fixture']);
    $account = $db->upsertAccount($bank, ['account_number' => '123', 'account_name' => 'Offline account']);
    $db->setSetting('auto_sync_last_run_timestamp', '0');
    $output = $run();
    if ($db->getSetting('offline_paypal_calls') !== '2' || $db->getSetting('auto_sync_last_status') !== 'partial'
        || $db->getBankAuthorizationState($bank)['reason'] !== 'automatic_tan_prevention_unavailable') {
        throw new RuntimeException('FinTS safe skip prevented PayPal or was reported as success: ' . $output);
    }
    $db->setAccountBackgroundSync($account, false);
    $db->setSetting('auto_sync_last_run_timestamp', '0');
    $output = $run();
    if ($db->getSetting('offline_paypal_calls') !== '3' || $db->getSetting('auto_sync_last_status') !== 'success'
        || !str_contains($output, 'no_background_accounts')) {
        throw new RuntimeException('Disabled account selection not respected: ' . $output);
    }
    $db->setSetting('offline_paypal_partial', '1');
    $db->setSetting('auto_sync_last_run_timestamp', '0');
    $output = $run();
    if ($db->getSetting('auto_sync_last_status') !== 'partial'
        || !str_contains($output, '"paypal_partial":1') || !str_contains($output, '"balances_updated":1')
        || !str_contains($output, '"paypal_synced":0')) {
        throw new RuntimeException('Cron discarded partial PayPal operation statistics: ' . $output);
    }
    $output = $run(true);
    $stats = json_decode($output, true)['stats'];
    if ($stats['paypal_partial'] !== 1 || $stats['balances_updated'] !== 1 || $stats['paypal_synced'] !== 0
        || $db->getSetting('auto_sync_last_status') !== 'partial') {
        throw new RuntimeException('API discarded partial PayPal operation statistics: ' . $output);
    }
    echo "Safe sync regression tests passed (PayPal-only, mixed, disabled accounts, partial cron/API; offline).\n";
} finally {
    unset($db);
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
