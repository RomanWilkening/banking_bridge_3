#!/usr/bin/env php
<?php
/**
 * MQTT Auto-Publish CLI script
 * Run this via cron to automatically publish account data to MQTT
 * 
 * Usage: php bin/mqtt-publish.php
 */

declare(strict_types=1);

// Bootstrap the application
require __DIR__ . '/../vendor/autoload.php';

use App\Services\DatabaseService;
use App\Services\MqttService;
use App\Services\AuthorizationService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Create logger
$logger = new Logger('mqtt-publish');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Keep the lock with the persistent database, not in an ephemeral global directory.
$dataPath = getenv('DATA_PATH') ?: '/data';
if (!is_dir($dataPath)) {
    exit(0);
}
$lockFile = $dataPath . '/mqtt-publish.lock';
$lockFp = fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $logger->debug('Another mqtt-publish instance is already running, skipping');
    exit(0);
}

// Rotate log file if it exceeds 1MB
$logFile = '/var/log/mqtt-publish.log';
if (file_exists($logFile) && filesize($logFile) > 1048576) {
    $lastLines = [];
    exec('tail -n 100 ' . escapeshellarg($logFile) . ' 2>/dev/null', $lastLines);
    file_put_contents($logFile, implode("\n", $lastLines) . "\n");
}

$db = null;

try {
    // Initialize database
    $dbPath = $dataPath . '/banking.db';
    
    if (!file_exists($dbPath)) {
        $logger->debug('Database not found, skipping');
        exit(0);
    }
    
    $db = new DatabaseService($dbPath);

    // Expire abandoned local challenges even with bank synchronization disabled.
    // This sweep only touches local operation state and never constructs a bank client.
    AuthorizationService::expirePendingOperations($db);
    
    // Check if MQTT is enabled
    $mqttEnabled = $db->getSetting('mqtt_enabled', '0') === '1';
    if (!$mqttEnabled) {
        $logger->debug('MQTT is disabled');
        exit(0);
    }

    // Authorization changes and local expiry are independent of balance scheduling.
    $mqttService = new MqttService($logger, $db);
    $authorization = $mqttService->publishAuthorizationStates();
    if (!$authorization['success']) {
        $db->setSetting('mqtt_last_status', 'error');
        $db->setSetting('mqtt_last_error', $authorization['message']);
        $logger->warning('MQTT authorization publish failed', ['message' => $authorization['message']]);
        exit(1);
    }
    
    // Check if auto-publish is enabled
    $autoPublishEnabled = $db->getSetting('mqtt_auto_publish_enabled', '1') === '1';
    if (!$autoPublishEnabled) {
        $logger->debug('MQTT auto-publish is disabled');
        exit(0);
    }
    
    // Check interval
    $interval = (int) $db->getSetting('mqtt_auto_publish_interval', '1'); // Default 1 minute
    $lastPublish = (int) $db->getSetting('mqtt_last_publish_timestamp', '0');
    $now = time();
    
    // Only publish if enough time has passed
    $intervalSeconds = $interval * 60;
    if (($now - $lastPublish) < $intervalSeconds) {
        $remainingSeconds = $intervalSeconds - ($now - $lastPublish);
        $logger->debug("Not yet time for MQTT publish, {$remainingSeconds}s remaining");
        exit(0);
    }
    
    $logger->info('Starting MQTT auto-publish');
    
    // Initialize MQTT service and publish
    $result = $mqttService->publishAccountBalances();
    
    // Update timestamp and status
    if ($result['success']) {
        $db->setSetting('mqtt_last_publish_timestamp', (string) $now);
        $db->setSetting('mqtt_last_publish', date('d.m.Y H:i:s'));
        $logger->info('MQTT auto-publish completed', [
            'published' => $result['published'] ?? 0,
            'errors' => count($result['errors'] ?? [])
        ]);
        $db->setSetting('mqtt_last_status', 'success');
        $db->setSetting('mqtt_last_error', '');
    } else {
        $errorMsg = $result['message'] ?? 'Unknown error';
        $logger->warning('MQTT auto-publish failed', ['message' => $errorMsg]);
        $db->setSetting('mqtt_last_status', 'error');
        $db->setSetting('mqtt_last_error', $errorMsg);
        exit(1);
    }
    
} catch (\Throwable $e) {
    $logger->error('MQTT publish error: ' . $e->getMessage());
    
    // Save error status (only if $db was initialized)
    if ($db !== null) {
        try {
            $db->setSetting('mqtt_last_status', 'error');
            $db->setSetting('mqtt_last_error', $e->getMessage());
        } catch (\Throwable $e2) {
            // Ignore
        }
    }
    
    exit(1);
}

exit(0);
