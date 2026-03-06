#!/usr/bin/env php
<?php
/**
 * Auto-sync CLI script
 * Run this via cron to automatically sync all banks
 * 
 * Usage: php bin/auto-sync.php
 */

declare(strict_types=1);

// Bootstrap the application
require __DIR__ . '/../vendor/autoload.php';

use App\Services\DatabaseService;
use App\Services\FinTSService;
use App\Services\MqttService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Create logger
$logger = new Logger('auto-sync');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$logger->info('=== AUTO SYNC CRON STARTED ===');

// Lock file to prevent concurrent runs
$lockFile = '/tmp/auto-sync.lock';
$lockFp = fopen($lockFile, 'w');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $logger->info('Another auto-sync instance is already running, skipping');
    exit(0);
}

// Rotate log file if it exceeds 1MB
$logFile = '/var/log/auto-sync.log';
if (file_exists($logFile) && filesize($logFile) > 1048576) {
    $lastLines = [];
    exec('tail -n 100 ' . escapeshellarg($logFile) . ' 2>/dev/null', $lastLines);
    file_put_contents($logFile, implode("\n", $lastLines) . "\n");
}

$db = null;

try {
    // Initialize database
    $dataPath = getenv('DATA_PATH') ?: '/data';
    $dbPath = $dataPath . '/banking.db';
    
    if (!file_exists($dbPath)) {
        $logger->warning('Database not found, skipping sync', ['path' => $dbPath]);
        exit(0);
    }
    
    $db = new DatabaseService($dbPath);
    
    // Check if auto-sync is enabled
    $enabled = $db->getSetting('auto_sync_enabled', '0');
    if ($enabled !== '1') {
        $logger->info('Auto-sync is disabled, skipping');
        exit(0);
    }
    
    // Check interval
    $interval = (int) $db->getSetting('auto_sync_interval', '30');
    $lastRun = $db->getSetting('auto_sync_last_run_timestamp', '0');
    $now = time();
    
    // Only run if enough time has passed since last run
    $intervalSeconds = $interval * 60;
    if (($now - (int)$lastRun) < $intervalSeconds) {
        $remainingMinutes = ceil(($intervalSeconds - ($now - (int)$lastRun)) / 60);
        $logger->info("Not yet time for sync, waiting {$remainingMinutes} more minutes");
        exit(0);
    }
    
    // Get FinTS product ID
    $productId = $db->getSetting('fints_product_id');
    if (empty($productId)) {
        $logger->error('FinTS product ID not configured');
        exit(1);
    }
    
    // Initialize FinTS service
    $fintsService = new FinTSService($logger);
    $fintsService->setProductId($productId);
    
    // Get all banks
    $banks = $db->getAllBanks();
    
    if (empty($banks)) {
        $logger->info('No banks configured');
        exit(0);
    }
    
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
        
        $logger->info("Processing bank: {$bankName}");
        
        // Get accounts
        $accounts = $db->getAccountsByBankId($bankId);
        if (empty($accounts)) {
            $logger->info("Skipping {$bankName} - no accounts");
            $totalStats['banks_skipped']++;
            continue;
        }
        
        // Check if any account requires manual TAN approval
        $hasManualTanApproval = !empty(array_filter($accounts, function($account) {
            return !empty($account['tan_manual_approval']);
        }));
        
        // Try to use existing session
        $session = $db->getFinTSSession($bankId);
        $persistedInstance = $session ? $session['session_data'] : null;
        
        try {
            $result = $fintsService->syncAll(
                [
                    'bank_code' => $bank['bank_code'],
                    'fints_url' => $bank['fints_url'],
                    'username' => $bank['username'],
                    'password' => $bank['password']
                ],
                $accounts,
                $persistedInstance
            );
            
            // Skip if TAN required and manual approval is configured
            if (isset($result['needs_tan']) && $result['needs_tan']) {
                if ($hasManualTanApproval) {
                    $logger->warning("Skipping {$bankName} - TAN required, manual approval configured");
                    $db->logActivity(
                        'auto_sync_skipped',
                        'warning',
                        'Auto-Sync übersprungen - TAN erforderlich, manuelle Freigabe konfiguriert',
                        $bankId
                    );
                } else {
                    $logger->warning("Skipping {$bankName} - TAN required");
                    $db->logActivity(
                        'auto_sync_skipped',
                        'warning',
                        'Auto-Sync übersprungen - TAN erforderlich',
                        $bankId
                    );
                }
                $totalStats['banks_skipped']++;
                continue;
            }
            
            if (!$result['success']) {
                $logger->error("Sync failed for {$bankName}: " . ($result['message'] ?? 'Unknown'));
                $totalStats['errors'][] = $bankName;
                continue;
            }
            
            // Process results
            $results = $result['results'] ?? [];
            
            // Update balances
            foreach ($results['balances'] ?? [] as $accountId => $balance) {
                $db->updateAccountBalance($accountId, $balance['amount'], $balance['date']);
                $totalStats['balances_updated']++;
            }
            
            // Save transactions
            foreach ($results['transactions'] ?? [] as $accountId => $transactions) {
                $txResult = $db->saveTransactions($accountId, $transactions);
                $totalStats['transactions_new'] += $txResult['new'];
            }
            
            // Save holdings
            foreach ($results['holdings'] ?? [] as $accountId => $holdings) {
                $count = $db->saveSecuritiesHoldings($accountId, $holdings);
                $totalStats['holdings_updated'] += $count;
                
                $totalValue = $db->getDepotTotalValue($accountId);
                if ($totalValue !== null) {
                    $db->updateAccountBalance($accountId, $totalValue, date('Y-m-d H:i:s'));
                }
            }
            
            // Save session
            if (isset($result['persisted_instance'])) {
                $db->saveFinTSSession($bankId, $result['persisted_instance']);
            }
            
            $totalStats['banks_synced']++;
            
            $db->logActivity(
                'auto_sync',
                'success',
                sprintf("Auto-Sync abgeschlossen"),
                $bankId
            );
            
            $logger->info("Successfully synced {$bankName}");
            
        } catch (\Throwable $e) {
            $logger->error("Exception for {$bankName}: " . $e->getMessage());
            $totalStats['errors'][] = $bankName;
        }
    }
    
    // Sync PayPal accounts
    $paypalAccounts = $db->getAllPayPalAccounts();
    if (!empty($paypalAccounts)) {
        $logger->info('Syncing PayPal accounts', ['count' => count($paypalAccounts)]);
        
        // Load PayPalService
        $paypalService = new \App\Services\PayPalService($logger, $db);
        
        foreach ($paypalAccounts as $paypal) {
            $paypalName = $paypal['name'] ?? 'PayPal';
            try {
                $logger->info("Syncing PayPal: {$paypalName}");
                $result = $paypalService->syncAccount($paypal['id']);
                
                if ($result['success']) {
                    $logger->info("Successfully synced PayPal {$paypalName}", [
                        'balance' => $result['balance'] ?? null,
                        'new_transactions' => $result['transactions_new'] ?? 0
                    ]);
                } else {
                    $logger->warning("PayPal sync returned failure for {$paypalName}");
                    $totalStats['errors'][] = 'PayPal: ' . $paypalName;
                }
            } catch (\Throwable $e) {
                $logger->error("PayPal sync error for {$paypalName}: " . $e->getMessage());
                $totalStats['errors'][] = 'PayPal: ' . $paypalName;
            }
        }
    }
    
    // Update timestamps and status
    $db->setSetting('auto_sync_last_run', date('d.m.Y H:i'));
    $db->setSetting('auto_sync_last_run_timestamp', (string) time());
    
    if (empty($totalStats['errors'])) {
        $db->setSetting('auto_sync_last_status', 'success');
        $db->setSetting('auto_sync_last_error', '');
    } else {
        $db->setSetting('auto_sync_last_status', 'partial');
        $db->setSetting('auto_sync_last_error', 'Fehler bei: ' . implode(', ', $totalStats['errors']));
    }
    
    // Publish to MQTT if enabled
    $mqttService = new MqttService($logger, $db);
    if ($mqttService->isEnabled()) {
        $mqttResult = $mqttService->publishAccountBalances();
        $logger->info('MQTT publish result', $mqttResult);
    }
    
    $logger->info('=== AUTO SYNC COMPLETED ===', $totalStats);
    
} catch (\Throwable $e) {
    $logger->error('Fatal error: ' . $e->getMessage());
    
    // Save error status (only if $db was initialized)
    if ($db !== null) {
        try {
            $db->setSetting('auto_sync_last_status', 'error');
            $db->setSetting('auto_sync_last_error', $e->getMessage());
            $db->setSetting('auto_sync_last_run', date('d.m.Y H:i'));
            $db->setSetting('auto_sync_last_run_timestamp', (string) time());
        } catch (\Throwable $e2) {
            // Ignore
        }
    }
    
    exit(1);
}

exit(0);
