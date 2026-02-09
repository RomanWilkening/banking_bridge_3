<?php
declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;

/**
 * PayPal Classic API (NVP) Service
 * Uses the TransactionSearch and GetBalance APIs
 */
class PayPalService
{
    private const API_ENDPOINT_LIVE = 'https://api-3t.paypal.com/nvp';
    private const API_ENDPOINT_SANDBOX = 'https://api-3t.sandbox.paypal.com/nvp';
    private const API_VERSION = '124.0';
    
    private Logger $logger;
    private DatabaseService $db;
    private bool $sandbox = false;
    
    public function __construct(Logger $logger, DatabaseService $db)
    {
        $this->logger = $logger;
        $this->db = $db;
    }
    
    /**
     * Set sandbox mode for testing
     */
    public function setSandbox(bool $sandbox): void
    {
        $this->sandbox = $sandbox;
    }
    
    /**
     * Get API endpoint based on mode
     */
    private function getEndpoint(): string
    {
        return $this->sandbox ? self::API_ENDPOINT_SANDBOX : self::API_ENDPOINT_LIVE;
    }
    
    /**
     * Make NVP API call
     */
    private function call(array $credentials, string $method, array $params = []): array
    {
        $nvpData = array_merge([
            'USER' => $credentials['api_username'],
            'PWD' => $credentials['api_password'],
            'SIGNATURE' => $credentials['api_signature'],
            'METHOD' => $method,
            'VERSION' => self::API_VERSION,
        ], $params);
        
        $this->logger->debug('PayPal API call', ['method' => $method]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->getEndpoint(),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($nvpData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->logger->error('PayPal API curl error', ['error' => $error]);
            return ['success' => false, 'error' => 'Connection error: ' . $error];
        }
        
        if ($httpCode !== 200) {
            $this->logger->error('PayPal API HTTP error', ['code' => $httpCode]);
            return ['success' => false, 'error' => 'HTTP error: ' . $httpCode];
        }
        
        // Parse NVP response
        parse_str($response, $result);
        
        $ack = $result['ACK'] ?? 'Failure';
        if (!in_array($ack, ['Success', 'SuccessWithWarning'])) {
            $errorMsg = $result['L_LONGMESSAGE0'] ?? $result['L_SHORTMESSAGE0'] ?? 'Unknown error';
            $errorCode = $result['L_ERRORCODE0'] ?? '';
            $this->logger->error('PayPal API error', ['ack' => $ack, 'error' => $errorMsg, 'code' => $errorCode]);
            return ['success' => false, 'error' => $errorMsg, 'error_code' => $errorCode];
        }
        
        return ['success' => true, 'data' => $result];
    }
    
    /**
     * Test API credentials
     */
    public function testCredentials(array $credentials): array
    {
        // Use TransactionSearch with a very short time window as a test
        $result = $this->call($credentials, 'TransactionSearch', [
            'STARTDATE' => date('Y-m-d\TH:i:s\Z', strtotime('-1 day')),
            'ENDDATE' => date('Y-m-d\TH:i:s\Z'),
        ]);
        
        if ($result['success']) {
            return [
                'success' => true,
                'message' => 'PayPal API-Verbindung erfolgreich!'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'PayPal API-Fehler: ' . ($result['error'] ?? 'Unbekannter Fehler')
        ];
    }
    
    /**
     * Get account balance
     */
    public function getBalance(array $credentials): array
    {
        $result = $this->call($credentials, 'GetBalance', [
            'RETURNALLCURRENCIES' => '1'
        ]);
        
        if (!$result['success']) {
            return $result;
        }
        
        $data = $result['data'];
        $balances = [];
        
        // Primary balance
        if (isset($data['L_AMT0'])) {
            $balances[] = [
                'amount' => (float) $data['L_AMT0'],
                'currency' => $data['L_CURRENCYCODE0'] ?? 'EUR'
            ];
        }
        
        // Additional currencies
        $i = 1;
        while (isset($data["L_AMT{$i}"])) {
            $balances[] = [
                'amount' => (float) $data["L_AMT{$i}"],
                'currency' => $data["L_CURRENCYCODE{$i}"] ?? 'EUR'
            ];
            $i++;
        }
        
        return [
            'success' => true,
            'balances' => $balances,
            'primary_balance' => $balances[0]['amount'] ?? 0,
            'primary_currency' => $balances[0]['currency'] ?? 'EUR'
        ];
    }
    
    /**
     * Search transactions
     */
    public function searchTransactions(array $credentials, ?\DateTime $startDate = null, ?\DateTime $endDate = null): array
    {
        // Default: Last 90 days (PayPal allows up to 3 years)
        if (!$startDate) {
            $days = (int) $this->db->getSetting('transaction_history_days', '90');
            $startDate = new \DateTime("-{$days} days");
        }
        if (!$endDate) {
            $endDate = new \DateTime();
        }
        
        $this->logger->info('PayPal TransactionSearch', [
            'start' => $startDate->format('Y-m-d'),
            'end' => $endDate->format('Y-m-d')
        ]);
        
        $result = $this->call($credentials, 'TransactionSearch', [
            'STARTDATE' => $startDate->format('Y-m-d\TH:i:s\Z'),
            'ENDDATE' => $endDate->format('Y-m-d\TH:i:s\Z'),
        ]);
        
        if (!$result['success']) {
            return $result;
        }
        
        $data = $result['data'];
        $transactions = [];
        
        // Parse transactions from response (L_* fields)
        $i = 0;
        while (isset($data["L_TRANSACTIONID{$i}"])) {
            $tx = [
                'transaction_id' => $data["L_TRANSACTIONID{$i}"],
                'timestamp' => $this->parsePayPalDate($data["L_TIMESTAMP{$i}"] ?? null),
                'type' => $data["L_TYPE{$i}"] ?? null,
                'email' => $data["L_EMAIL{$i}"] ?? null,
                'name' => $data["L_NAME{$i}"] ?? null,
                'status' => $data["L_STATUS{$i}"] ?? null,
                'amount' => (float) ($data["L_AMT{$i}"] ?? 0),
                'fee_amount' => (float) ($data["L_FEEAMT{$i}"] ?? 0),
                'net_amount' => (float) ($data["L_NETAMT{$i}"] ?? 0),
                'currency' => $data["L_CURRENCYCODE{$i}"] ?? 'EUR',
                'subject' => $data["L_SUBJECT{$i}"] ?? null,
            ];
            $transactions[] = $tx;
            $i++;
        }
        
        $this->logger->info('PayPal transactions found', ['count' => count($transactions)]);
        
        return [
            'success' => true,
            'transactions' => $transactions,
            'count' => count($transactions)
        ];
    }
    
    /**
     * Parse PayPal date format
     */
    private function parsePayPalDate(?string $date): ?string
    {
        if (!$date) {
            return null;
        }
        
        try {
            $dt = new \DateTime($date);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Sync PayPal account (get balance and transactions)
     */
    public function syncAccount(int $paypalAccountId): array
    {
        $account = $this->db->getPayPalAccountById($paypalAccountId);
        if (!$account) {
            return ['success' => false, 'message' => 'PayPal-Konto nicht gefunden'];
        }
        
        $credentials = [
            'api_username' => $account['api_username'],
            'api_password' => $account['api_password'],
            'api_signature' => $account['api_signature'],
        ];
        
        $this->logger->info('Syncing PayPal account', ['id' => $paypalAccountId, 'name' => $account['name']]);
        
        // Get balance
        $balanceResult = $this->getBalance($credentials);
        if ($balanceResult['success']) {
            $this->db->updatePayPalAccountBalance(
                $paypalAccountId,
                $balanceResult['primary_balance'],
                date('Y-m-d H:i:s')
            );
        }
        
        // Get transactions (configurable, default 90 days)
        $txResult = $this->searchTransactions($credentials);
        $newTransactions = 0;
        
        if ($txResult['success']) {
            $saveResult = $this->db->savePayPalTransactions($paypalAccountId, $txResult['transactions']);
            $newTransactions = $saveResult['new'];
        }
        
        return [
            'success' => true,
            'balance' => $balanceResult['success'] ? $balanceResult['primary_balance'] : null,
            'currency' => $balanceResult['success'] ? $balanceResult['primary_currency'] : 'EUR',
            'transactions_found' => $txResult['count'] ?? 0,
            'transactions_new' => $newTransactions,
            'message' => sprintf(
                'Sync erfolgreich. Saldo: %.2f %s, %d neue Transaktionen',
                $balanceResult['primary_balance'] ?? 0,
                $balanceResult['primary_currency'] ?? 'EUR',
                $newTransactions
            )
        ];
    }
}
