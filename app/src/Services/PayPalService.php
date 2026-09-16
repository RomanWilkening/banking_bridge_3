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
    protected function call(array $credentials, string $method, array $params = []): array
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
            'STARTDATE' => gmdate('Y-m-d\TH:i:s\Z', strtotime('-1 day')),
            'ENDDATE' => gmdate('Y-m-d\TH:i:s\Z'),
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
        
        $i = 0;
        while (isset($data["L_AMT{$i}"])) {
            if (!is_numeric($data["L_AMT{$i}"]) || !is_finite((float) $data["L_AMT{$i}"])
                || !preg_match('/^[A-Z]{3}$/D', $data["L_CURRENCYCODE{$i}"] ?? '')) {
                return ['success' => false, 'error' => 'Invalid PayPal balance response'];
            }
            $balances[] = [
                'amount' => (float) $data["L_AMT{$i}"],
                'currency' => $data["L_CURRENCYCODE{$i}"]
            ];
            $i++;
        }
        if (!$balances) {
            return ['success' => false, 'error' => 'PayPal response did not contain a balance'];
        }
        
        return [
            'success' => true,
            'balances' => $balances,
            'primary_balance' => $balances[0]['amount'],
            'primary_currency' => $balances[0]['currency']
        ];
    }
    
    /**
     * Search transactions
     */
    public function searchTransactions(array $credentials, ?\DateTime $startDate = null, ?\DateTime $endDate = null): array
    {
        // Default: Last 30 days
        if (!$startDate) {
            $startDate = new \DateTime('-30 days');
        }
        if (!$endDate) {
            $endDate = new \DateTime();
        }
        $startDate = (clone $startDate)->setTimezone(new \DateTimeZone('UTC'));
        $endDate = (clone $endDate)->setTimezone(new \DateTimeZone('UTC'));
        
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
        if (($data['ACK'] ?? '') === 'SuccessWithWarning') {
            return ['success' => false, 'error' => 'PayPal transaction search returned incomplete results'];
        }
        $transactions = [];
        
        // Parse transactions from response (L_* fields)
        $i = 0;
        while (isset($data["L_TRANSACTIONID{$i}"])) {
            if (trim($data["L_TRANSACTIONID{$i}"]) === ''
                || !isset($data["L_AMT{$i}"]) || !is_numeric($data["L_AMT{$i}"])
                || !is_finite((float) $data["L_AMT{$i}"])
                || !preg_match('/^[A-Z]{3}$/D', $data["L_CURRENCYCODE{$i}"] ?? '')) {
                return ['success' => false, 'error' => 'Invalid PayPal transaction response'];
            }
            $tx = [
                'transaction_id' => $data["L_TRANSACTIONID{$i}"],
                'timestamp' => $this->parsePayPalDate($data["L_TIMESTAMP{$i}"] ?? null),
                'type' => $data["L_TYPE{$i}"] ?? null,
                'email' => $data["L_EMAIL{$i}"] ?? null,
                'name' => $data["L_NAME{$i}"] ?? null,
                'status' => $data["L_STATUS{$i}"] ?? null,
                'amount' => (float) $data["L_AMT{$i}"],
                'fee_amount' => (float) ($data["L_FEEAMT{$i}"] ?? 0),
                'net_amount' => (float) ($data["L_NETAMT{$i}"] ?? 0),
                'currency' => $data["L_CURRENCYCODE{$i}"],
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
            $dt = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
        
        try {
            $balanceResult = $this->getBalance($credentials);
            if ($balanceResult['success']) {
                $this->db->updatePayPalAccountBalance(
                    $paypalAccountId,
                    $balanceResult['primary_balance'],
                    gmdate('Y-m-d H:i:s'),
                    $balanceResult['primary_currency']
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('PayPal balance synchronization failed', ['id' => $paypalAccountId]);
            $balanceResult = ['success' => false, 'error' => 'PayPal balance synchronization failed'];
        }

        $newTransactions = 0;
        try {
            $txResult = $this->searchTransactions($credentials);
            if ($txResult['success']) {
                $saveResult = $this->db->savePayPalTransactions($paypalAccountId, $txResult['transactions']);
                $newTransactions = $saveResult['new'];
            }
        } catch (\Throwable $e) {
            $this->logger->error('PayPal transaction synchronization failed', ['id' => $paypalAccountId]);
            $txResult = ['success' => false, 'error' => 'PayPal transaction synchronization failed'];
        }

        $success = $balanceResult['success'] && $txResult['success'];
        $partial = (bool) $balanceResult['success'] !== (bool) $txResult['success'];
        $operations = [];
        $errors = [];
        foreach (['balance' => $balanceResult, 'transactions' => $txResult] as $operation => $result) {
            $error = $result['success'] ? null : ($result['error'] ?? 'PayPal operation failed');
            $operations[$operation] = ['success' => (bool) $result['success'], 'error' => $error];
            if ($error !== null) {
                $errors[$operation] = $error;
            }
        }
        return [
            'success' => $success,
            'partial' => $partial,
            'operations' => $operations,
            'errors' => $errors,
            'balance' => $balanceResult['success'] ? $balanceResult['primary_balance'] : null,
            'currency' => $balanceResult['success'] ? $balanceResult['primary_currency'] : $account['currency'],
            'transactions_found' => $txResult['count'] ?? 0,
            'transactions_new' => $newTransactions,
            'message' => $success ? sprintf(
                'Sync erfolgreich. Saldo: %.2f %s, %d neue Transaktionen',
                $balanceResult['primary_balance'] ?? 0,
                $balanceResult['primary_currency'] ?? 'EUR',
                $newTransactions
            ) : ($partial ? 'Sync teilweise erfolgreich. ' : 'Sync fehlgeschlagen. ') . implode('; ', $errors)
        ];
    }
}
