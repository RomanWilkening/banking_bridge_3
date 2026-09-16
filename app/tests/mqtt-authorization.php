<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\DatabaseService;
use App\Services\MqttService;
use Monolog\Logger;

final class MqttTestDatabase extends DatabaseService
{
    public array $banks = [];
    public array $states = [];
    public array $accounts = [];
    public array $paypal = [];
    private PDO $connection;

    public function __construct(string $path)
    {
        $this->connection = new PDO('sqlite:' . $path);
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->connection->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');
    }

    public function getPdo(): PDO { return $this->connection; }
    public function getAllBanks(): array { return $this->banks; }
    public function getBankAuthorizationState(int $bankId): array { return $this->states[$bankId] ?? ['status' => 'unknown']; }
    public function getMqttEnabledAccounts(): array { return $this->accounts; }
    public function getMqttEnabledPayPalAccounts(): array { return $this->paypal; }
    public function getSetting(string $key, ?string $default = null): ?string
    {
        $query = $this->connection->prepare('SELECT value FROM settings WHERE key = ?');
        $query->execute([$key]);
        $value = $query->fetchColumn();
        return $value === false ? $default : $value;
    }
    public function setSetting(string $key, string $value): bool
    {
        return $this->connection->prepare('INSERT OR REPLACE INTO settings VALUES (?, ?)')->execute([$key, $value]);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function bytes($stream, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Socket closed');
        }
        $data .= $chunk;
    }
    return $data;
}

function broker($server, string $trace, string $noAck): never
{
    while ($stream = @stream_socket_accept($server, 30)) {
        stream_set_timeout($stream, 20);
        try {
            while (true) {
                $header = ord(bytes($stream, 1));
                $length = 0;
                $multiplier = 1;
                do {
                    $byte = ord(bytes($stream, 1));
                    $length += ($byte & 127) * $multiplier;
                    $multiplier *= 128;
                } while (($byte & 128) !== 0);
                $body = $length > 0 ? bytes($stream, $length) : '';
                if (($header >> 4) === 1) {
                    $clientLength = unpack('n', substr($body, 10, 2))[1];
                    file_put_contents($trace, json_encode(['client_id' => substr($body, 12, $clientLength)]) . "\n", FILE_APPEND);
                    fwrite($stream, "\x20\x02\x00\x00");
                } elseif (($header >> 4) === 3) {
                    $topicLength = unpack('n', substr($body, 0, 2))[1];
                    $qos = ($header >> 1) & 3;
                    $offset = 2 + $topicLength;
                    $packetId = $qos > 0 ? substr($body, $offset, 2) : '';
                    $payload = substr($body, $offset + ($qos > 0 ? 2 : 0));
                    $ack = !file_exists($noAck);
                    file_put_contents($trace, json_encode([
                        'topic' => substr($body, 2, $topicLength), 'payload' => $payload,
                        'qos' => $qos, 'retained' => ($header & 1) === 1, 'ack' => $ack,
                    ]) . "\n", FILE_APPEND);
                    if ($qos === 1 && $ack) {
                        fwrite($stream, "\x40\x02" . $packetId);
                    }
                } elseif (($header >> 4) === 12) {
                    fwrite($stream, "\xd0\x00");
                } elseif (($header >> 4) === 14) {
                    break;
                }
            }
        } catch (Throwable $e) {
            // Client disconnects and ACK timeouts are intentionally exercised.
        }
        fclose($stream);
    }
    exit(0);
}

function messages(string $trace): array
{
    $lines = file_exists($trace) ? file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    return array_values(array_filter(array_map(fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines),
        fn($row) => isset($row['topic'])));
}

function retained(string $trace): array
{
    $result = [];
    foreach (messages($trace) as $message) {
        if ($message['payload'] === '') {
            unset($result[$message['topic']]);
        } else {
            $result[$message['topic']] = json_decode($message['payload'], true, 512, JSON_THROW_ON_ERROR);
        }
    }
    return $result;
}

$directory = sys_get_temp_dir() . '/banking-mqtt-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$pid = null;
$ownerPid = getmypid();
$cleanupFixture = static function () use ($directory, &$pid, $ownerPid): void {
    if (getmypid() !== $ownerPid) {
        return;
    }
    if ($pid !== null && $pid > 0) {
        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);
        $pid = null;
    }
    if (is_dir($directory)) {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
};
register_shutdown_function($cleanupFixture);
$trace = $directory . '/broker.jsonl';
$noAck = $directory . '/no-ack';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
check($server !== false, $error);
$port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
$pid = pcntl_fork();
check($pid !== -1, 'Could not fork MQTT fixture');
if ($pid === 0) {
    broker($server, $trace, $noAck);
}
fclose($server);
$oldEnvironment = [];
foreach (['MQTT_HOST', 'MQTT_PORT', 'MQTT_USER', 'MQTT_PASSWORD', 'MQTT_TOPIC_PREFIX'] as $key) {
    $oldEnvironment[$key] = getenv($key);
}
try {
    putenv('MQTT_HOST=127.0.0.1');
    putenv('MQTT_PORT=' . $port);
    putenv('MQTT_USER=');
    putenv('MQTT_PASSWORD=');
    putenv('MQTT_TOPIC_PREFIX=banking');
    $db = new MqttTestDatabase($directory . '/mock-banking.db');
    $logger = new Logger('mqtt-test');
    $service = new MqttService($logger, $db);
    check(!$service->isEnabled(), 'Environment must not bypass the explicit enable gate');
    $db->setSetting('mqtt_enabled', '1');
    check($service->isEnabled(), 'Environment host default must be supported');
    $db->setSetting('mqtt_host', '');
    check(!$service->isEnabled(), 'Explicit empty database host must override environment');
    $db->setSetting('mqtt_host', '127.0.0.1');
    $db->banks = [
        ['id' => 1, 'name' => 'First connection', 'bank_code' => '01234567', 'username' => 'DO_NOT_EXPORT', 'iban' => 'DO_NOT_EXPORT'],
        ['id' => 2, 'name' => 'Second connection', 'bank_code' => '01234567', 'session' => 'DO_NOT_EXPORT'],
    ];
    $db->states[1] = ['status' => 'authorized', 'authenticated_at' => '2026-01-01T01:00:00+01:00',
        'reason' => 'DO_NOT_EXPORT', 'tan' => 'DO_NOT_EXPORT', 'challenge' => 'DO_NOT_EXPORT'];
    $db->states[2] = ['status' => 'required'];
    $first = $service->publishAuthorizationStates();
    check($first['success'] && $first['published'] === 4, 'Initial connection-only authorization discovery/state publish');
    $values = retained($trace);
    check($values['banking/banks/1/authorization']['authenticated_at'] === '2026-01-01T00:00:00Z', 'Normalize UTC');
    check($values['banking/banks/1/authorization']['expires_at'] === null, 'Never fabricate bank expiry');
    check($values['banking/banks/1/authorization']['status'] === 'authorized'
        && $values['banking/banks/2/authorization']['status'] === 'required', 'Connections sharing a BLZ stay independent');
    check(!isset($values['banking/institutes/01234567/authorization']), 'No BLZ aggregation topics');
    check($values['homeassistant/binary_sensor/banking_authorization_bank_1/config']['device']['name'] === 'First connection', 'Use bank connection name for display');
    check(!str_contains(file_get_contents($trace), 'DO_NOT_EXPORT'), 'Sensitive fields must not reach MQTT');
    check($values['banking/banks/1/authorization']['reason'] === 'unspecified', 'Only allowlisted machine reasons are exposed');
    foreach (messages($trace) as $message) {
        check($message['qos'] === 1 && $message['retained'] && $message['ack'], 'Every publication retained and QoS1 acknowledged');
    }
    $count = count(messages($trace));
    $restart = new MqttService($logger, $db);
    check($restart->publishAuthorizationStates()['published'] === 0 && count(messages($trace)) === $count, 'Dedup across service restart');
    $beforeBrokerLoss = retained($trace);
    file_put_contents($trace, '');
    check(retained($trace) === [], 'Simulate broker restart losing all retained messages');
    $inventory = json_decode($db->getSetting('mqtt_authorization_publications'), true, 512, JSON_THROW_ON_ERROR);
    $brokerKey = array_key_first($inventory);
    $inventory[$brokerKey]['last_full_publish'] = time() - 3601;
    $db->setSetting('mqtt_authorization_publications', json_encode($inventory, JSON_THROW_ON_ERROR));
    $refreshed = $restart->publishAuthorizationStates();
    check($refreshed['success'] && $refreshed['full_refresh'] && $refreshed['refresh_interval_seconds'] === 3600
        && $refreshed['published'] === 4, 'Hourly refresh reannounces every discovery and state topic');
    check(retained($trace) === $beforeBrokerLoss, 'Hourly refresh restores broker-retained discovery and state');
    check($restart->publishAuthorizationStates()['published'] === 0, 'Normal minute publication remains deduplicated after refresh');
    $inventory = json_decode($db->getSetting('mqtt_authorization_publications'), true, 512, JSON_THROW_ON_ERROR);
    $brokerKey = array_key_first($inventory);
    $legacyState = 'banking/institutes/01234567/authorization';
    $legacyDiscovery = 'homeassistant/binary_sensor/banking_authorization_institute_01234567/config';
    $inventory[$brokerKey]['topics'][$legacyState] = hash('sha256', 'previously-published');
    $inventory[$brokerKey]['topics'][$legacyDiscovery] = hash('sha256', 'previously-published');
    $db->setSetting('mqtt_authorization_publications', json_encode($inventory, JSON_THROW_ON_ERROR));
    check($restart->publishAuthorizationStates()['published'] === 2, 'Obsolete BLZ state/discovery inventory is cleaned');
    $cleanup = array_slice(messages($trace), -2);
    check(array_column($cleanup, 'topic') === [$legacyState, $legacyDiscovery]
        && array_column($cleanup, 'payload') === ['', ''], 'Retained obsolete topics receive empty tombstones');
    foreach (['pending', 'error', 'unknown'] as $status) {
        $db->states[2] = ['status' => $status];
        check($restart->publishAuthorizationStates()['success'], 'Publish changed state');
        check(retained($trace)['banking/banks/2/authorization']['status'] === $status, 'Explicit connection state: ' . $status);
    }
    $db->states[2] = ['status' => 'required', 'reason' => 'automatic_tan_prevention_unavailable'];
    check($restart->publishAuthorizationStates()['success'], 'Publish unattended FinTS fail-closed reason');
    check(retained($trace)['banking/banks/2/authorization']['reason'] === 'automatic_tan_prevention_unavailable', 'Preserve safe backend prevention reason');
    $db->states[1]['expires_at'] = '2000-01-01T00:00:00Z';
    $db->states[1]['status'] = 'required';
    $db->states[1]['reason'] = 'local_authorization_expired';
    check($restart->publishAuthorizationStates()['success'], 'Publish authoritative local expiry state');
    $expired = retained($trace)['banking/banks/1/authorization'];
    check($expired['status'] === 'required' && $expired['reason'] === 'local_authorization_expired', 'Preserve DB-authoritative local expiry');
    check($expired['expiry_basis'] === 'local_policy', 'Deadline is explicitly local policy, not bank-guaranteed expiry');
    check($expired['authenticated_at'] === '2026-01-01T00:00:00Z', 'Local expiry preserves factual authentication timestamp');
    unset($db->states[1]['expires_at']);
    $db->states[1]['status'] = 'authorized';
    $db->states[1]['reason'] = 'DO_NOT_EXPORT';
    file_put_contents($noAck, '1');
    $db->states[2] = ['status' => 'pending'];
    $inventory = json_decode($db->getSetting('mqtt_authorization_publications'), true, 512, JSON_THROW_ON_ERROR);
    $lastSuccessfulRefresh = time() - 3601;
    $inventory[$brokerKey]['last_full_publish'] = $lastSuccessfulRefresh;
    $db->setSetting('mqtt_authorization_publications', json_encode($inventory, JSON_THROW_ON_ERROR));
    $failed = $restart->publishAuthorizationStates();
    check(!$failed['success'], 'Missing PUBACK must fail, not silently succeed on loop timeout');
    $inventory = json_decode($db->getSetting('mqtt_authorization_publications'), true, 512, JSON_THROW_ON_ERROR);
    check(in_array(null, array_values(array_values($inventory)[0]['topics']), true), 'Unacknowledged publications remain pending');
    check($inventory[$brokerKey]['last_full_publish'] === $lastSuccessfulRefresh, 'Failed refresh never advances full-publication timestamp');
    unlink($noAck);
    $reopened = new MqttTestDatabase($directory . '/mock-banking.db');
    $reopened->banks = $db->banks;
    $reopened->states = $db->states;
    $service = new MqttService($logger, $reopened);
    $retry = $service->publishAuthorizationStates();
    check($retry['success'] && $retry['full_refresh'] && $retry['published'] === 4, 'Retry complete refresh after outage and process state reload');
    $inventory = json_decode($reopened->getSetting('mqtt_authorization_publications'), true, 512, JSON_THROW_ON_ERROR);
    check($inventory[$brokerKey]['last_full_publish'] > $lastSuccessfulRefresh, 'Successful all-ACK refresh advances full-publication timestamp');
    $reopened->setSetting('mqtt_topic_prefix', 'new');
    check($service->publishAuthorizationStates()['success'], 'Prefix change reconciles inventory');
    $values = retained($trace);
    check(!isset($values['banking/banks/1/authorization']) && isset($values['new/banks/1/authorization']), 'Prefix change clears old retained topics');
    check($values['homeassistant/binary_sensor/banking_authorization_bank_1/config']['state_topic'] === 'new/banks/1/authorization', 'Discovery tracks prefix with stable unique ID');
    $reopened->banks[0]['name'] = 'Renamed connection';
    check($service->publishAuthorizationStates()['published'] === 1, 'Renaming a connection updates only its display discovery');
    $renamed = retained($trace)['homeassistant/binary_sensor/banking_authorization_bank_1/config'];
    check($renamed['device']['name'] === 'Renamed connection'
        && $renamed['unique_id'] === 'banking_authorization_bank_1'
        && $renamed['state_topic'] === 'new/banks/1/authorization', 'Bank name changes do not change identity or state topic');
    $reopened->banks = [];
    check($service->publishAuthorizationStates()['success'] && retained($trace) === [], 'Deleting all banks removes retained states and discovery');
    $reopened->banks = $db->banks;
    $reopened->states = $db->states;
    check($service->publishAccountBalances()['success'], 'Authorization works when no account export flags enabled');
    check(isset(retained($trace)['new/banks/1/authorization']), 'Balances publisher includes authorization without exported accounts');
    $reopened->accounts = [['id' => 5, 'bank_id' => 1, 'bank_name' => 'Example', 'account_name' => 'Null balance',
        'balance' => null, 'balance_date' => '2020-01-01']];
    $reopened->paypal = [['id' => 8, 'name' => 'Wallet', 'balance' => null, 'last_sync' => '2021-01-01']];
    check($service->publishAccountBalances()['success'], 'Publish nullable balances');
    $values = retained($trace);
    check($values['new/example/null_balance_5']['balance'] === null, 'Null bank balance remains unknown');
    check($values['new/paypal/wallet_8']['balance'] === null, 'Null PayPal balance remains unknown');
    check($values['new/example/null_balance_5']['balance_date'] === '2020-01-01'
        && $values['new/example/null_balance_5']['published_at'] !== '2020-01-01', 'Balance date differs from publication date');
    check($values['new/paypal/wallet_8']['last_sync'] === '2021-01-01', 'Preserve actual PayPal sync date');
    $reopened->accounts[0]['balance'] = INF;
    $reopened->paypal[0]['balance'] = INF;
    check(!$service->publishAccountBalances()['success'], 'All-failed account publication cannot report success');
    $reopened->accounts = [];
    $reopened->paypal = [];
    $reopened->setSetting('mqtt_host', 'localhost');
    $switched = $service->publishAuthorizationStates();
    check($switched['success'] && count($switched['warnings']) === 1, 'Broker switch reports retained-topic cleanup limitation');
    $inventory = json_decode($reopened->getSetting('mqtt_authorization_publications'), true, 512, JSON_THROW_ON_ERROR);
    check(count($inventory) === 2, 'Old broker inventory is not silently discarded');
    $rows = array_map(fn($line) => json_decode($line, true), file($trace, FILE_IGNORE_NEW_LINES));
    $clientIds = array_column($rows, 'client_id');
    check(count($clientIds) === count(array_unique($clientIds)), 'Concurrent/restarted publisher client IDs must be unique');
    $realDatabase = new DatabaseService($directory . '/banking.db');
    $bankId = $realDatabase->createBank([
        'name' => 'Local policy fixture', 'bank_code' => '01234567', 'fints_url' => 'https://bank.invalid',
        'username' => 'fixture', 'password' => 'fixture',
    ]);
    $realDatabase->setSetting('mqtt_enabled', '1');
    $realDatabase->setSetting('mqtt_topic_prefix', 'database-check');
    $realDatabase->setBankAuthorizationState($bankId, 'authorized');
    $authenticated = $realDatabase->getBankAuthorizationState($bankId)['authenticated_at'];
    $realDatabase->getPdo()->prepare('UPDATE bank_authorization SET expires_at = ? WHERE bank_id = ?')
        ->execute(['2000-01-01T00:00:00Z', $bankId]);
    check((new MqttService($logger, $realDatabase))->publishAuthorizationStates()['success'], 'Real database local-policy integration');
    $actualState = $realDatabase->getBankAuthorizationState($bankId);
    $actualPayload = retained($trace)["database-check/banks/{$bankId}/authorization"];
    check($actualState['status'] === 'required' && $actualState['reason'] === 'local_authorization_expired',
        'MQTT getter causes authoritative persisted expiry without any bank request');
    check($actualPayload['status'] === $actualState['status']
        && $actualPayload['reason'] === $actualState['reason']
        && $actualPayload['authenticated_at'] === $authenticated
        && $actualPayload['expiry_basis'] === 'local_policy', 'MQTT matches persisted local expiry and factual history');
    $realDatabase->setSetting('auto_sync_enabled', '0');
    $realDatabase->setSetting('mqtt_auto_publish_enabled', '0');
    $realDatabase->setBankAuthorizationState($bankId, 'pending', 'tan_confirmation_pending');
    $realDatabase->saveFinTSSession($bankId, 'PRIVATE_SESSION_FIXTURE');
    $realDatabase->getPdo()->exec('CREATE TABLE IF NOT EXISTS fints_authorization_operations (
        bank_id INTEGER PRIMARY KEY, operation_id TEXT NOT NULL, owner_hash TEXT NOT NULL,
        request_id TEXT NOT NULL, status TEXT NOT NULL, payload TEXT, response TEXT,
        expires_at INTEGER NOT NULL, next_poll_at INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE
    )');
    $realDatabase->getPdo()->prepare('INSERT INTO fints_authorization_operations
        (bank_id, operation_id, owner_hash, request_id, status, payload, response, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $bankId, 'fixture-operation', hash('sha256', 'fixture-owner'), 'fixture-request', 'pending',
            '{"challenge":"PRIVATE_CHALLENGE_FIXTURE"}', '{}', time() - 1,
        ]);
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/mqtt-publish.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['DATA_PATH' => $directory]));
    check(is_resource($process), 'Start MQTT-only cron fixture');
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'MQTT-only cron failed: ' . $output . $errors);
    $operation = $realDatabase->getPdo()->query('SELECT * FROM fints_authorization_operations')->fetch(PDO::FETCH_ASSOC);
    check($operation['status'] === 'expired' && $operation['payload'] === null,
        'MQTT-only cron clears expired pending challenge even when all synchronization is disabled');
    check($realDatabase->getFinTSSession($bankId, true) === null, 'Expired operation removes its stale technical session');
    $cronState = $realDatabase->getBankAuthorizationState($bankId);
    $cronPayload = retained($trace)["database-check/banks/{$bankId}/authorization"];
    check($cronState['status'] === 'required' && $cronState['reason'] === 'authorization_expired'
        && $cronPayload['status'] === 'required' && $cronPayload['reason'] === 'authorization_expired',
        'MQTT-only cron persists and publishes operation expiry distinct from local maximum age');
    check(!str_contains(file_get_contents($trace), 'PRIVATE_'), 'MQTT-only cleanup never exports private operation/session data');
    echo "PASS MQTT authorization: retained QoS1/ACK, privacy, independent bank IDs, expiry, dedup, restart retry, cleanup, environment, nullable balances, failures\n";
} finally {
    foreach ($oldEnvironment as $key => $value) {
        putenv($value === false ? $key : $key . '=' . $value);
    }
    $cleanupFixture();
}
