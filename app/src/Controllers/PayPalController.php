<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\PayPalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Monolog\Logger;
use Slim\Views\Twig;

class PayPalController
{
    private Twig $view;
    private DatabaseService $db;
    private PayPalService $paypal;
    private Logger $logger;
    
    public function __construct(Twig $view, DatabaseService $db, PayPalService $paypal, Logger $logger)
    {
        $this->view = $view;
        $this->db = $db;
        $this->paypal = $paypal;
        $this->logger = $logger;
    }
    
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
    
    /**
     * List all PayPal accounts
     */
    public function index(Request $request, Response $response): Response
    {
        $accounts = $this->db->getAllPayPalAccounts();
        
        return $this->view->render($response, 'paypal/index.twig', [
            'accounts' => $accounts
        ]);
    }
    
    /**
     * Show add PayPal form
     */
    public function add(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'paypal/add.twig');
    }
    
    /**
     * Store new PayPal account
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        // Validate required fields
        if (empty($data['name']) || empty($data['api_username']) || 
            empty($data['api_password']) || empty($data['api_signature'])) {
            return $this->view->render($response, 'paypal/add.twig', [
                'error' => 'Bitte alle Pflichtfelder ausfüllen',
                'data' => $data
            ]);
        }
        
        // Test credentials
        $testResult = $this->paypal->testCredentials([
            'api_username' => $data['api_username'],
            'api_password' => $data['api_password'],
            'api_signature' => $data['api_signature'],
        ]);
        
        if (!$testResult['success']) {
            return $this->view->render($response, 'paypal/add.twig', [
                'error' => $testResult['message'],
                'data' => $data
            ]);
        }
        
        // Create account
        $accountId = $this->db->createPayPalAccount([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'api_username' => $data['api_username'],
            'api_password' => $data['api_password'],
            'api_signature' => $data['api_signature'],
            'currency' => $data['currency'] ?? 'EUR',
        ]);
        
        $this->logger->info('PayPal account created', ['id' => $accountId, 'name' => $data['name']]);
        
        // Initial sync
        $this->paypal->syncAccount($accountId);
        
        return $response
            ->withHeader('Location', '/paypal/' . $accountId)
            ->withStatus(302);
    }
    
    /**
     * Show PayPal account details
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $account = $this->db->getPayPalAccountById($accountId);
        
        if (!$account) {
            return $response->withStatus(404);
        }
        
        $transactions = $this->db->getPayPalTransactions($accountId, 50);
        $transactionCount = $this->db->getPayPalTransactionCount($accountId);
        
        return $this->view->render($response, 'paypal/show.twig', [
            'account' => $account,
            'transactions' => $transactions,
            'transaction_count' => $transactionCount
        ]);
    }
    
    /**
     * Delete PayPal account
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $account = $this->db->getPayPalAccountById($accountId);
        
        if ($account) {
            $this->db->deletePayPalAccount($accountId);
            $this->logger->info('PayPal account deleted', ['id' => $accountId, 'name' => $account['name']]);
        }
        
        return $response
            ->withHeader('Location', '/paypal')
            ->withStatus(302);
    }
    
    // API Methods
    
    /**
     * Test PayPal credentials
     */
    public function testCredentials(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        if (empty($data['api_username']) || empty($data['api_password']) || empty($data['api_signature'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bitte alle API-Credentials angeben'
            ]);
        }
        
        $result = $this->paypal->testCredentials([
            'api_username' => $data['api_username'],
            'api_password' => $data['api_password'],
            'api_signature' => $data['api_signature'],
        ]);
        
        return $this->jsonResponse($response, $result);
    }
    
    /**
     * Sync PayPal account
     */
    public function sync(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $account = $this->db->getPayPalAccountById($accountId);
        
        if (!$account) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'PayPal-Konto nicht gefunden'
            ], 404);
        }
        
        $result = $this->paypal->syncAccount($accountId);
        
        return $this->jsonResponse($response, $result);
    }
    
    /**
     * Get PayPal transactions
     */
    public function getTransactions(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $queryParams = $request->getQueryParams();
        $limit = (int) ($queryParams['limit'] ?? 50);
        $offset = (int) ($queryParams['offset'] ?? 0);
        
        $transactions = $this->db->getPayPalTransactions($accountId, $limit, $offset);
        $total = $this->db->getPayPalTransactionCount($accountId);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'transactions' => $transactions,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }
    
    /**
     * Get PayPal balance
     */
    public function getBalance(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $account = $this->db->getPayPalAccountById($accountId);
        
        if (!$account) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'PayPal-Konto nicht gefunden'
            ], 404);
        }
        
        $result = $this->paypal->getBalance([
            'api_username' => $account['api_username'],
            'api_password' => $account['api_password'],
            'api_signature' => $account['api_signature'],
        ]);
        
        return $this->jsonResponse($response, $result);
    }
    
    /**
     * Set MQTT export flag
     */
    public function setMqttExport(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $data = $request->getParsedBody() ?? [];
        $enabled = !empty($data['enabled']);
        
        $this->db->setPayPalAccountMqttExport($accountId, $enabled);
        
        return $this->jsonResponse($response, [
            'success' => true,
            'mqtt_export' => $enabled
        ]);
    }
}
