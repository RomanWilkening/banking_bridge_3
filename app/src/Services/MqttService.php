<?php
declare(strict_types=1);

namespace App\Services;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use Monolog\Logger;

class MqttService
{
    private ?MqttClient $client = null;
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
        $host = $this->db->getSetting('mqtt_host', '');
        
        return $enabled && !empty($host);
    }
    
    /**
     * Get MQTT configuration
     */
    private function getConfig(): array
    {
        return [
            'host' => $this->db->getSetting('mqtt_host', ''),
            'port' => (int) $this->db->getSetting('mqtt_port', '1883'),
            'username' => $this->db->getSetting('mqtt_user', ''),
            'password' => $this->db->getSetting('mqtt_password', ''),
            'topic_prefix' => $this->db->getSetting('mqtt_topic_prefix', 'banking'),
            'client_id' => 'banking_bridge_' . substr(md5(gethostname()), 0, 8),
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
            $this->client = new MqttClient(
                $config['host'],
                $config['port'],
                $config['client_id']
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
            
            $this->client->connect($connectionSettings);
            
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
        if ($this->client !== null && $this->client->isConnected()) {
            try {
                $this->client->disconnect();
            } catch (\Throwable $e) {
                // Ignore disconnect errors
            }
        }
        $this->client = null;
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
        
        if (!$this->connect()) {
            return ['success' => false, 'message' => 'MQTT-Verbindung fehlgeschlagen'];
        }
        
        $config = $this->getConfig();
        $topicPrefix = $config['topic_prefix'];
        $published = 0;
        $errors = [];
        $details = [];
        
        try {
            // Get all accounts that have MQTT export enabled
            $accounts = $this->db->getMqttEnabledAccounts();
            
            $this->logger->info('MQTT accounts to publish', [
                'count' => count($accounts),
                'accounts' => array_map(fn($a) => [
                    'id' => $a['id'],
                    'name' => $a['account_name'] ?? 'unknown',
                    'bank' => $a['bank_name'] ?? 'unknown',
                    'balance' => $a['balance'] ?? null
                ], $accounts)
            ]);
            
            foreach ($accounts as $account) {
                try {
                    $result = $this->publishAccountBalance($account, $topicPrefix);
                    $published++;
                    $details[] = [
                        'account_id' => $account['id'],
                        'name' => $account['account_name'],
                        'bank' => $account['bank_name'],
                        'topic' => $result['topic'],
                        'balance' => $result['balance'],
                        'status' => 'ok'
                    ];
                } catch (\Throwable $e) {
                    $errorMsg = $e->getMessage();
                    $errors[] = ($account['account_name'] ?? 'Konto ' . $account['id']) . ': ' . $errorMsg;
                    $details[] = [
                        'account_id' => $account['id'],
                        'name' => $account['account_name'] ?? 'unknown',
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
                'success' => true,
                'message' => $message,
                'published' => $published,
                'errors' => $errors,
                'details' => $details
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
        $accountName = $account['account_name'] ?? 'Konto';
        $bankName = $account['bank_name'] ?? 'Bank';
        $accountType = $account['account_type'] ?? 'checking';
        $balance = $account['balance'];
        $currency = $account['currency'] ?? 'EUR';
        $iban = $account['iban'] ?? '';
        
        $this->logger->debug('Publishing account', [
            'account_id' => $accountId,
            'account_name' => $accountName,
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
        
        // Handle null/empty balance - use 0 as default
        $balanceValue = $balance !== null ? round((float) $balance, 2) : 0;
        
        // Discovery payload for Home Assistant
        $discoveryPayload = [
            'name' => $accountName,
            'unique_id' => $uniqueId,
            'object_id' => $uniqueId, // Helps with entity_id creation
            'state_topic' => $stateTopic,
            'value_template' => '{{ value_json.balance }}',
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
            'last_update' => date('c'),
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
