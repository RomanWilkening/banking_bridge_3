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
        if ($this->client !== null && $this->client->isConnected()) {
            return true;
        }
        
        $config = $this->getConfig();
        
        if (empty($config['host'])) {
            $this->logger->warning('MQTT host not configured');
            return false;
        }
        
        try {
            $this->client = new MqttClient(
                $config['host'],
                $config['port'],
                $config['client_id']
            );
            
            $connectionSettings = (new ConnectionSettings())
                ->setKeepAliveInterval(60)
                ->setConnectTimeout(5)
                ->setSocketTimeout(5);
            
            if (!empty($config['username'])) {
                $connectionSettings->setUsername($config['username']);
            }
            if (!empty($config['password'])) {
                $connectionSettings->setPassword($config['password']);
            }
            
            $this->client->connect($connectionSettings);
            
            $this->logger->info('MQTT connected', ['host' => $config['host']]);
            return true;
            
        } catch (MqttClientException $e) {
            $this->logger->error('MQTT connection failed', ['error' => $e->getMessage()]);
            $this->client = null;
            return false;
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
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'MQTT nicht aktiviert'];
        }
        
        if (!$this->connect()) {
            return ['success' => false, 'message' => 'MQTT-Verbindung fehlgeschlagen'];
        }
        
        $config = $this->getConfig();
        $topicPrefix = $config['topic_prefix'];
        $published = 0;
        $errors = [];
        
        try {
            // Get all accounts that have MQTT export enabled
            $accounts = $this->db->getMqttEnabledAccounts();
            
            foreach ($accounts as $account) {
                try {
                    $this->publishAccountBalance($account, $topicPrefix);
                    $published++;
                } catch (\Throwable $e) {
                    $errors[] = $account['account_name'] . ': ' . $e->getMessage();
                    $this->logger->error('Failed to publish account', [
                        'account_id' => $account['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $this->disconnect();
            
            $message = "{$published} Konto(en) veröffentlicht";
            if (!empty($errors)) {
                $message .= ', ' . count($errors) . ' Fehler';
            }
            
            return [
                'success' => true,
                'message' => $message,
                'published' => $published,
                'errors' => $errors
            ];
            
        } catch (\Throwable $e) {
            $this->disconnect();
            $this->logger->error('MQTT publish failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Fehler: ' . $e->getMessage()];
        }
    }
    
    /**
     * Publish a single account's balance with Home Assistant discovery
     */
    private function publishAccountBalance(array $account, string $topicPrefix): void
    {
        $accountId = $account['id'];
        $accountName = $account['account_name'] ?? 'Konto';
        $bankName = $account['bank_name'] ?? 'Bank';
        $accountType = $account['account_type'] ?? 'checking';
        $balance = $account['balance'] ?? 0;
        $currency = $account['currency'] ?? 'EUR';
        $iban = $account['iban'] ?? '';
        
        // Create unique ID for this sensor
        $uniqueId = 'banking_' . $accountId;
        
        // Create safe topic name (no spaces, special chars)
        $safeName = $this->sanitizeTopicName($accountName);
        $safeBankName = $this->sanitizeTopicName($bankName);
        
        // State topic
        $stateTopic = "{$topicPrefix}/{$safeBankName}/{$safeName}";
        
        // Home Assistant discovery topic
        $discoveryTopic = "homeassistant/sensor/{$uniqueId}/config";
        
        // Determine icon and device class based on account type
        $icon = $accountType === 'depot' ? 'mdi:chart-line' : 'mdi:bank';
        $deviceClass = 'monetary';
        
        // Discovery payload for Home Assistant
        $discoveryPayload = [
            'name' => $accountName,
            'unique_id' => $uniqueId,
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
        ];
        
        // State payload
        $statePayload = [
            'balance' => round((float) $balance, 2),
            'currency' => $currency,
            'account_name' => $accountName,
            'account_type' => $accountType,
            'bank' => $bankName,
            'iban' => $iban,
            'last_update' => date('c'),
        ];
        
        // Publish discovery config (retained)
        $this->client->publish(
            $discoveryTopic,
            json_encode($discoveryPayload),
            MqttClient::QOS_AT_LEAST_ONCE,
            true // retained
        );
        
        // Publish state (retained)
        $this->client->publish(
            $stateTopic,
            json_encode($statePayload),
            MqttClient::QOS_AT_LEAST_ONCE,
            true // retained
        );
        
        $this->logger->info('Published account to MQTT', [
            'account_id' => $accountId,
            'topic' => $stateTopic,
            'balance' => $balance
        ]);
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
