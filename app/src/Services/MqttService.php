<?php
declare(strict_types=1);

namespace App\Services;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\Repositories\MemoryRepository;
use Monolog\Logger;

class MqttService
{
    private const AUTHORIZATION_REANNOUNCE_INTERVAL = 3600;
    private ?MqttClient $client = null;
    private ?MemoryRepository $repository = null;
    private Logger $logger;
    private DatabaseService $db;
    
    public function __construct(Logger $logger, DatabaseService $db)
    {
        $this->logger = $logger;
        $this->db = $db;
    }
    
    /**
     * Check if MQTT is enabled and configured
     */
    public function isEnabled(): bool
    {
        $enabled = $this->db->getSetting('mqtt_enabled', '0') === '1';
        $host = $this->getConfig()['host'];
        
        return $enabled && !empty($host);
    }
    
    /**
     * Get MQTT configuration
     */
    private function getConfig(): array
    {
        return [
            'host' => $this->db->getSetting('mqtt_host', getenv('MQTT_HOST') ?: ''),
            'port' => (int) $this->db->getSetting('mqtt_port', getenv('MQTT_PORT') ?: '1883'),
            'username' => $this->db->getSetting('mqtt_user', getenv('MQTT_USER') ?: ''),
            'password' => $this->db->getSetting('mqtt_password', getenv('MQTT_PASSWORD') ?: ''),
            'topic_prefix' => trim($this->db->getSetting('mqtt_topic_prefix', getenv('MQTT_TOPIC_PREFIX') ?: 'banking'), '/'),
            'client_id' => 'bb_' . bin2hex(random_bytes(10)),
        ];
    }
    
    /**
     * Connect to MQTT broker
     */
    private function connect(): bool
    {
        // Check if already connected
        if ($this->client !== null) {
            try {
                if ($this->client->isConnected()) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Connection check failed, will reconnect
                $this->logger->debug('Connection check failed, reconnecting', ['error' => $e->getMessage()]);
            }
            $this->client = null;
        }
        
        $config = $this->getConfig();
        
        if (empty($config['host'])) {
            $this->logger->warning('MQTT host not configured');
            return false;
        }
        
        $this->logger->info('Connecting to MQTT', [
            'host' => $config['host'],
            'port' => $config['port'],
            'client_id' => $config['client_id'],
            'has_username' => !empty($config['username'])
        ]);
        
        try {
            $this->repository = new MemoryRepository();
            $this->client = new MqttClient(
                $config['host'],
                $config['port'],
                $config['client_id'],
                MqttClient::MQTT_3_1_1,
                $this->repository
            );
            
            $connectionSettings = (new ConnectionSettings())
                ->setKeepAliveInterval(60)
                ->setConnectTimeout(10)
                ->setSocketTimeout(10)
                ->setResendTimeout(5);
            
            if (!empty($config['username'])) {
                $connectionSettings->setUsername($config['username']);
            }
            if (!empty($config['password'])) {
                $connectionSettings->setPassword($config['password']);
            }
            
            $this->client->connect($connectionSettings, true);
            
            $this->logger->info('MQTT connected successfully', ['host' => $config['host']]);
            return true;
            
        } catch (MqttClientException $e) {
            $this->logger->error('MQTT connection failed', [
                'error' => $e->getMessage(),
                'host' => $config['host'],
                'port' => $config['port']
            ]);
            $this->client = null;
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('MQTT connection error', [
                'error' => $e->getMessage(),
                'type' => get_class($e)
            ]);
            $this->client = null;
            return false;
        }
    }
    
    /**
     * Ensure connection is active, reconnect if needed
     */
    private function ensureConnected(): bool
    {
        if ($this->client === null) {
            return $this->connect();
        }
        
        try {
            if (!$this->client->isConnected()) {
                $this->logger->info('MQTT connection lost, reconnecting...');
                $this->client = null;
                return $this->connect();
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('MQTT connection check failed', ['error' => $e->getMessage()]);
            $this->client = null;
            return $this->connect();
        }
    }
    
    /**
     * Disconnect from MQTT broker
     */
    private function disconnect(): void
    {
        try {
            if ($this->client !== null && $this->client->isConnected()) {
                $this->client->disconnect();
            }
        } catch (\Throwable $e) {
            // A failed publication remains pending in the durable inventory.
        }
        $this->client = null;
        $this->repository = null;
    }

    private function flushPublications(): void
    {
        if ($this->client === null || $this->repository === null) {
            throw new \RuntimeException('MQTT not connected');
        }
        // php-mqtt/client 1.8 returns normally on timeout and disconnect does not flush.
        $this->client->loop(true, true, 10);
        if ($this->repository->countPendingOutgoingMessages() !== 0) {
            throw new \RuntimeException('MQTT acknowledgement timed out');
        }
    }

    /**
     * Publish local authorization knowledge without contacting a bank or exporting balances.
     * Inventory is retained per broker: switching brokers cannot erase the old broker's
     * retained messages without its connection settings/credentials. Return a warning
     * and keep that inventory so switching back can reconcile it.
     */
    public function publishAuthorizationStates(): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'MQTT nicht aktiviert', 'published' => 0];
        }
        $published = 0;
        $warnings = [];
        $fullRefresh = false;
        $lock = null;
        try {
            $database = $this->db->getPdo()->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC);
            $directory = !empty($database[0]['file']) ? dirname($database[0]['file']) : dirname(__DIR__, 2);
            $lock = fopen($directory . '/mqtt-authorization.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('MQTT authorization publisher is already running or lock unavailable');
            }
            $config = $this->getConfig();
            $prefix = $config['topic_prefix'];
            if ($prefix === '' || strpbrk($prefix, "+#\0") !== false) {
                throw new \RuntimeException('Invalid MQTT topic prefix');
            }
            $desired = $this->authorizationTopics($prefix);
            $inventory = json_decode($this->db->getSetting('mqtt_authorization_publications', '{}'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($inventory)) {
                throw new \RuntimeException('Invalid MQTT publication inventory');
            }
            $broker = hash('sha256', json_encode([$config['host'], $config['port']], JSON_THROW_ON_ERROR));
            foreach ($inventory as $key => $entry) {
                if ($key !== $broker && !empty($entry['topics'])) {
                    $warnings[] = 'Retained authorization topics remain on a previous broker; reconnect to that broker to reconcile them.';
                    break;
                }
            }
            $inventory[$broker] ??= ['topics' => []];
            $lastFullPublish = (int) ($inventory[$broker]['last_full_publish'] ?? 0);
            $now = time();
            $fullRefresh = $lastFullPublish <= 0 || $lastFullPublish > $now
                || ($now - $lastFullPublish) >= self::AUTHORIZATION_REANNOUNCE_INTERVAL;
            $topics = &$inventory[$broker]['topics'];
            $changes = [];
            foreach ($topics as $topic => $hash) {
                if (!array_key_exists($topic, $desired)) {
                    $changes[$topic] = '';
                }
            }
            foreach ($desired as $topic => $payload) {
                if ($fullRefresh || ($topics[$topic] ?? null) !== hash('sha256', $payload)) {
                    $changes[$topic] = $payload;
                }
            }
            if ($changes !== []) {
                if (!$this->connect()) {
                    throw new \RuntimeException('MQTT-Verbindung fehlgeschlagen');
                }
                foreach ($changes as $topic => $payload) {
                    // Record intent before sending: even a crash after broker ACK can be cleaned up.
                    $topics[$topic] = null;
                }
                $this->saveAuthorizationInventory($inventory);
                foreach ($changes as $topic => $payload) {
                    $this->client->publish($topic, $payload, MqttClient::QOS_AT_LEAST_ONCE, true);
                    $this->flushPublications();
                    if ($payload === '') {
                        unset($topics[$topic]);
                    } else {
                        $topics[$topic] = hash('sha256', $payload);
                    }
                    $this->saveAuthorizationInventory($inventory);
                    $published++;
                }
            }
            if ($fullRefresh) {
                // A broker may lose retained data on restart. Reannounce both discovery
                // and state hourly, advancing the deadline only after every PUBACK.
                $inventory[$broker]['last_full_publish'] = time();
                $this->saveAuthorizationInventory($inventory);
            }
            foreach ($warnings as $warning) {
                $this->logger->warning($warning);
            }
            return ['success' => true, 'message' => "{$published} Autorisierungsthemen veröffentlicht",
                'published' => $published, 'errors' => [], 'warnings' => $warnings,
                'full_refresh' => $fullRefresh, 'refresh_interval_seconds' => self::AUTHORIZATION_REANNOUNCE_INTERVAL];
        } catch (\Throwable $e) {
            $this->logger->error('MQTT authorization publication failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage(), 'published' => $published,
                'errors' => [$e->getMessage()], 'warnings' => $warnings,
                'full_refresh' => $fullRefresh, 'refresh_interval_seconds' => self::AUTHORIZATION_REANNOUNCE_INTERVAL];
        } finally {
            $this->disconnect();
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function saveAuthorizationInventory(array $inventory): void
    {
        if (!$this->db->setSetting('mqtt_authorization_publications', json_encode($inventory, JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('Could not persist MQTT publication inventory');
        }
    }

    private function authorizationTopics(string $prefix): array
    {
        $topics = [];
        foreach ($this->db->getAllBanks() as $bank) {
            $bankId = (int) $bank['id'];
            $raw = $this->db->getBankAuthorizationState($bankId);
            $status = in_array($raw['status'] ?? null, ['unknown', 'authorized', 'required', 'pending', 'error'], true)
                ? $raw['status'] : 'unknown';
            $payload = ['bank_id' => $bankId, 'status' => $status, 'reason' => $this->authorizationReason($raw['reason'] ?? null)];
            foreach (['authenticated_at', 'required_at', 'updated_at', 'expires_at'] as $field) {
                $payload[$field] = $this->authorizationTimestamp($raw[$field] ?? null);
            }
            $payload['expiry_basis'] = $payload['expires_at'] === null ? null : 'local_policy';
            $this->addAuthorizationTopics(
                $topics,
                "{$prefix}/banks/{$bankId}/authorization",
                "bank_{$bankId}",
                $payload,
                (string) ($bank['name'] ?? "Bank connection {$bankId}")
            );
        }
        return $topics;
    }

    private function authorizationTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function authorizationReason(mixed $reason): ?string
    {
        if ($reason === null) {
            return null;
        }
        return in_array($reason, ['authorization_expired', 'local_authorization_expired', 'authentication_required', 'authorization_required',
            'tan_required', 'tan_pending', 'sca_required', 'sca_pending', 'session_expired', 'session_missing',
            'authentication_failed', 'authorization_failed', 'bank_error', 'network_error', 'manual_authorization',
            'authorization_completed', 'automatic_tan_prevention_unavailable', 'explicit_authorization_started',
            'tan_confirmation_pending', 'authorization_poll_limit', 'authorization_cancelled', 'session_reset',
            'fints_operation_failed', 'authorization_operation_failed', 'not_authenticated',
            'legacy_session', 'unknown'], true) ? $reason : 'unspecified';
    }

    private function addAuthorizationTopics(array &$topics, string $stateTopic, string $id, array $payload, string $bankName): void
    {
        $uniqueId = 'banking_authorization_' . $id;
        $discovery = [
            'name' => 'Authorization required',
            'unique_id' => $uniqueId,
            'object_id' => $uniqueId,
            'state_topic' => $stateTopic,
            'device_class' => 'problem',
            'payload_on' => 'ON',
            'payload_off' => 'OFF',
            'value_template' => "{{ 'OFF' if value_json.status == 'authorized' else ('ON' if value_json.status in ['required', 'pending', 'error'] else 'None') }}",
            'json_attributes_topic' => $stateTopic,
            'device' => [
                'identifiers' => ['banking_bridge_' . $id],
                'name' => $bankName,
                'manufacturer' => 'Banking Bridge',
                'model' => 'Authorization',
            ],
        ];
        $topics["homeassistant/binary_sensor/{$uniqueId}/config"] = json_encode($discovery, JSON_THROW_ON_ERROR);
        $topics[$stateTopic] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    
    /**
     * Publish account balances to MQTT with Home Assistant auto-discovery
     */
    public function publishAccountBalances(): array
    {
        $this->logger->info('=== MQTT PUBLISH START ===');
        
        if (!$this->isEnabled()) {
            $this->logger->info('MQTT is disabled');
            return ['success' => false, 'message' => 'MQTT nicht aktiviert'];
        }
        
        $authorization = $this->publishAuthorizationStates();
        if (!$this->connect()) {
            return ['success' => false, 'message' => 'MQTT-Verbindung fehlgeschlagen'];
        }
        
        $config = $this->getConfig();
        $topicPrefix = $config['topic_prefix'];
        $published = 0;
        $errors = $authorization['success'] ? [] : ['Authorization: ' . $authorization['message']];
        $details = [];
        
        try {
            // Get all bank accounts that have MQTT export enabled
            $accounts = $this->db->getMqttEnabledAccounts();
            
            $this->logger->info('MQTT bank accounts to publish', [
                'count' => count($accounts),
                'accounts' => array_map(fn($a) => [
                    'id' => $a['id'],
                    'name' => $a['custom_name'] ?? $a['account_name'] ?? 'unknown',
                    'bank' => $a['bank_name'] ?? 'unknown',
                    'balance' => $a['balance'] ?? null
                ], $accounts)
            ]);
            
            foreach ($accounts as $account) {
                $displayName = $account['custom_name'] ?? $account['account_name'] ?? 'Konto';
                try {
                    $result = $this->publishAccountBalance($account, $topicPrefix);
                    $published++;
                    $details[] = [
                        'account_id' => $account['id'],
                        'name' => $displayName,
                        'bank' => $account['bank_name'],
                        'topic' => $result['topic'],
                        'balance' => $result['balance'],
                        'status' => 'ok'
                    ];
                } catch (\Throwable $e) {
                    $errorMsg = $e->getMessage();
                    $errors[] = $displayName . ': ' . $errorMsg;
                    $details[] = [
                        'account_id' => $account['id'],
                        'name' => $displayName,
                        'bank' => $account['bank_name'] ?? 'unknown',
                        'status' => 'error',
                        'error' => $errorMsg
                    ];
                    $this->logger->error('Failed to publish account', [
                        'account_id' => $account['id'],
                        'account_name' => $account['account_name'] ?? 'unknown',
                        'bank_name' => $account['bank_name'] ?? 'unknown',
                        'error' => $errorMsg,
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
            
            // Get all PayPal accounts that have MQTT export enabled
            $paypalAccounts = $this->db->getMqttEnabledPayPalAccounts();
            
            $this->logger->info('MQTT PayPal accounts to publish', ['count' => count($paypalAccounts)]);
            
            foreach ($paypalAccounts as $paypal) {
                $displayName = $paypal['name'] ?? 'PayPal';
                try {
                    $result = $this->publishPayPalBalance($paypal, $topicPrefix);
                    $published++;
                    $details[] = [
                        'paypal_id' => $paypal['id'],
                        'name' => $displayName,
                        'bank' => 'PayPal',
                        'topic' => $result['topic'],
                        'balance' => $result['balance'],
                        'status' => 'ok'
                    ];
                } catch (\Throwable $e) {
                    $errorMsg = $e->getMessage();
                    $errors[] = 'PayPal ' . $displayName . ': ' . $errorMsg;
                    $details[] = [
                        'paypal_id' => $paypal['id'],
                        'name' => $displayName,
                        'bank' => 'PayPal',
                        'status' => 'error',
                        'error' => $errorMsg
                    ];
                    $this->logger->error('Failed to publish PayPal account', [
                        'paypal_id' => $paypal['id'],
                        'name' => $displayName,
                        'error' => $errorMsg
                    ]);
                }
            }
            
            $this->disconnect();
            
            $this->logger->info('=== MQTT PUBLISH COMPLETE ===', [
                'published' => $published,
                'errors' => count($errors),
                'details' => $details
            ]);
            
            $message = "{$published} Konto(en) veröffentlicht";
            if (!empty($errors)) {
                $message .= ', ' . count($errors) . ' Fehler';
            }
            
            return [
                'success' => empty($errors),
                'message' => $message,
                'published' => $published,
                'errors' => $errors,
                'details' => $details,
                'authorization' => $authorization,
                'warnings' => $authorization['warnings'] ?? [],
            ];
            
        } catch (\Throwable $e) {
            $this->disconnect();
            $this->logger->error('MQTT publish failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['success' => false, 'message' => 'Fehler: ' . $e->getMessage()];
        }
    }
    
    /**
     * Publish a single account's balance with Home Assistant discovery
     * Returns details about what was published
     */
    private function publishAccountBalance(array $account, string $topicPrefix): array
    {
        $accountId = $account['id'];
        // Use custom_name if set, otherwise fall back to account_name
        $accountName = $account['custom_name'] ?? $account['account_name'] ?? 'Konto';
        $bankName = $account['bank_name'] ?? 'Bank';
        $accountType = $account['account_type'] ?? 'checking';
        $balance = $account['balance'];
        $currency = $account['currency'] ?? 'EUR';
        $iban = $account['iban'] ?? '';
        
        $this->logger->debug('Publishing account', [
            'account_id' => $accountId,
            'account_name' => $accountName,
            'custom_name' => $account['custom_name'] ?? null,
            'bank_name' => $bankName,
            'balance' => $balance,
            'balance_type' => gettype($balance)
        ]);
        
        // Create unique ID for this sensor
        $uniqueId = 'banking_' . $accountId;
        
        // Create safe topic name (no spaces, special chars)
        // Include account ID to ensure uniqueness even if names are identical
        $safeName = $this->sanitizeTopicName($accountName) . '_' . $accountId;
        $safeBankName = $this->sanitizeTopicName($bankName);
        
        // State topic - now includes account ID for uniqueness
        $stateTopic = "{$topicPrefix}/{$safeBankName}/{$safeName}";
        
        // Home Assistant discovery topic
        $discoveryTopic = "homeassistant/sensor/{$uniqueId}/config";
        
        // Determine icon and device class based on account type
        $icon = $accountType === 'depot' ? 'mdi:chart-line' : 'mdi:bank';
        $deviceClass = 'monetary';
        
        $balanceValue = $balance !== null && $balance !== '' ? round((float) $balance, 2) : null;
        
        // Discovery payload for Home Assistant
        $discoveryPayload = [
            'name' => $accountName,
            'unique_id' => $uniqueId,
            'object_id' => $uniqueId, // Helps with entity_id creation
            'state_topic' => $stateTopic,
            'value_template' => "{{ value_json.balance if value_json.balance is not none else 'None' }}",
            'unit_of_measurement' => $currency,
            'device_class' => $deviceClass,
            'state_class' => 'total',
            'icon' => $icon,
            'json_attributes_topic' => $stateTopic,
            'json_attributes_template' => '{{ value_json | tojson }}',
            'device' => [
                'identifiers' => ['banking_bridge_' . $account['bank_id']],
                'name' => $bankName,
                'manufacturer' => 'Banking Bridge',
                'model' => 'FinTS',
            ],
            'availability_mode' => 'all',
        ];
        
        // State payload
        $statePayload = [
            'balance' => $balanceValue,
            'currency' => $currency,
            'account_name' => $accountName,
            'account_type' => $accountType,
            'bank' => $bankName,
            'iban' => $iban,
            'account_id' => $accountId,
            'last_update' => $account['balance_date'] ?? null,
            'balance_date' => $account['balance_date'] ?? null,
            'published_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        
        $discoveryJson = json_encode($discoveryPayload, JSON_UNESCAPED_UNICODE);
        $stateJson = json_encode($statePayload, JSON_UNESCAPED_UNICODE);
        
        if ($discoveryJson === false) {
            throw new \RuntimeException('Failed to encode discovery payload: ' . json_last_error_msg());
        }
        if ($stateJson === false) {
            throw new \RuntimeException('Failed to encode state payload: ' . json_last_error_msg());
        }
        
        $this->logger->debug('MQTT payloads', [
            'discovery_topic' => $discoveryTopic,
            'discovery_payload' => $discoveryPayload,
            'state_topic' => $stateTopic,
            'state_payload' => $statePayload
        ]);
        
        // Ensure connection is still active
        if (!$this->ensureConnected()) {
            throw new \RuntimeException('MQTT connection lost and reconnect failed');
        }
        
        // Publish discovery config (retained)
        $this->client->publish(
            $discoveryTopic,
            $discoveryJson,
            MqttClient::QOS_AT_LEAST_ONCE,
            true // retained
        );
        $this->flushPublications();
        
        $this->logger->debug('Published discovery', ['topic' => $discoveryTopic]);
        
        // Small delay to ensure broker processes the discovery before state
        usleep(50000); // 50ms
        
        // Ensure connection is still active before state publish
        if (!$this->ensureConnected()) {
            throw new \RuntimeException('MQTT connection lost before state publish');
        }
        
        // Publish state (retained)
        $this->client->publish(
            $stateTopic,
            $stateJson,
            MqttClient::QOS_AT_LEAST_ONCE,
            true // retained
        );
        $this->flushPublications();
        
        $this->logger->info('Published account to MQTT', [
            'account_id' => $accountId,
            'account_name' => $accountName,
            'bank_name' => $bankName,
            'discovery_topic' => $discoveryTopic,
            'state_topic' => $stateTopic,
            'balance' => $balanceValue
        ]);
        
        return [
            'topic' => $stateTopic,
            'discovery_topic' => $discoveryTopic,
            'balance' => $balanceValue
        ];
    }
    
    /**
     * Publish PayPal account balance with Home Assistant discovery
     */
    private function publishPayPalBalance(array $paypal, string $topicPrefix): array
    {
        $paypalId = $paypal['id'];
        $accountName = $paypal['name'] ?? 'PayPal';
        $balance = $paypal['balance'];
        $currency = $paypal['currency'] ?? 'EUR';
        $email = $paypal['email'] ?? null;
        
        $this->logger->debug('Publishing PayPal account', [
            'paypal_id' => $paypalId,
            'name' => $accountName,
            'balance' => $balance,
            'currency' => $currency
        ]);
        
        // Create unique ID for this sensor
        $uniqueId = 'paypal_' . $paypalId;
        
        // Create safe topic name
        $safeName = $this->sanitizeTopicName($accountName) . '_' . $paypalId;
        
        // State topic
        $stateTopic = "{$topicPrefix}/paypal/{$safeName}";
        
        // Home Assistant discovery topic
        $discoveryTopic = "homeassistant/sensor/{$uniqueId}/config";
        
        // Handle null/empty balance
        $balanceValue = $balance !== null && $balance !== '' ? round((float) $balance, 2) : null;
        
        // Discovery payload for Home Assistant
        $discoveryPayload = [
            'name' => $accountName,
            'unique_id' => $uniqueId,
            'object_id' => $uniqueId,
            'state_topic' => $stateTopic,
            'value_template' => "{{ value_json.balance if value_json.balance is not none else 'None' }}",
            'unit_of_measurement' => $currency,
            'device_class' => 'monetary',
            'state_class' => 'total',
            'icon' => 'mdi:wallet',
            'json_attributes_topic' => $stateTopic,
            'json_attributes_template' => '{{ value_json | tojson }}',
            'device' => [
                'identifiers' => ['banking_bridge_paypal'],
                'name' => 'PayPal',
                'manufacturer' => 'Banking Bridge',
                'model' => 'PayPal API',
            ],
        ];
        
        // State payload
        $statePayload = [
            'balance' => $balanceValue,
            'currency' => $currency,
            'account_name' => $accountName,
            'email' => $email,
            'provider' => 'PayPal',
            'last_updated' => $paypal['last_sync'] ?? null,
            'last_sync' => $paypal['last_sync'] ?? null,
            'published_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        
        $discoveryJson = json_encode($discoveryPayload, JSON_THROW_ON_ERROR);
        $stateJson = json_encode($statePayload, JSON_THROW_ON_ERROR);
        
        if (!$this->ensureConnected()) {
            throw new \RuntimeException('MQTT connection lost');
        }
        
        // Publish discovery config (retained)
        $this->client->publish(
            $discoveryTopic,
            $discoveryJson,
            MqttClient::QOS_AT_LEAST_ONCE,
            true
        );
        $this->flushPublications();
        
        usleep(50000);
        
        if (!$this->ensureConnected()) {
            throw new \RuntimeException('MQTT connection lost before state publish');
        }
        
        // Publish state (retained)
        $this->client->publish(
            $stateTopic,
            $stateJson,
            MqttClient::QOS_AT_LEAST_ONCE,
            true
        );
        $this->flushPublications();
        
        $this->logger->info('Published PayPal to MQTT', [
            'paypal_id' => $paypalId,
            'name' => $accountName,
            'state_topic' => $stateTopic,
            'balance' => $balanceValue
        ]);
        
        return [
            'topic' => $stateTopic,
            'discovery_topic' => $discoveryTopic,
            'balance' => $balanceValue
        ];
    }
    
    /**
     * Remove Home Assistant discovery for an account
     */
    public function removeAccountDiscovery(int $accountId): bool
    {
        if (!$this->connect()) {
            return false;
        }
        
        try {
            $uniqueId = 'banking_' . $accountId;
            $discoveryTopic = "homeassistant/sensor/{$uniqueId}/config";
            
            // Publish empty payload to remove discovery
            $this->client->publish(
                $discoveryTopic,
                '',
                MqttClient::QOS_AT_LEAST_ONCE,
                true
            );
            $this->flushPublications();
            
            $this->disconnect();
            return true;
            
        } catch (\Throwable $e) {
            $this->disconnect();
            $this->logger->error('Failed to remove discovery', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Test MQTT connection
     */
    public function testConnection(): array
    {
        $config = $this->getConfig();
        
        if (empty($config['host'])) {
            return ['success' => false, 'message' => 'MQTT-Host nicht konfiguriert'];
        }
        
        try {
            $client = new MqttClient(
                $config['host'],
                $config['port'],
                $config['client_id'] . '_test'
            );
            
            $connectionSettings = (new ConnectionSettings())
                ->setKeepAliveInterval(60)
                ->setConnectTimeout(5);
            
            if (!empty($config['username'])) {
                $connectionSettings->setUsername($config['username']);
            }
            if (!empty($config['password'])) {
                $connectionSettings->setPassword($config['password']);
            }
            
            $client->connect($connectionSettings);
            $client->disconnect();
            
            return [
                'success' => true,
                'message' => 'Verbindung zu ' . $config['host'] . ':' . $config['port'] . ' erfolgreich'
            ];
            
        } catch (MqttClientException $e) {
            return [
                'success' => false,
                'message' => 'Verbindungsfehler: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Sanitize string for use in MQTT topic
     */
    private function sanitizeTopicName(string $name): string
    {
        // Replace umlauts
        $name = str_replace(
            ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'],
            ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'],
            $name
        );
        
        // Replace spaces and special chars with underscore
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
        
        // Remove multiple underscores
        $name = preg_replace('/_+/', '_', $name);
        
        // Trim underscores
        $name = trim($name, '_');
        
        return strtolower($name);
    }
}
