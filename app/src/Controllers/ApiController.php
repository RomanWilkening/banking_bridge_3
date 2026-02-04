<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\DatabaseService;
use App\Services\FinTSService;
use Monolog\Logger;

class ApiController
{
    public function __construct(
        private DatabaseService $db,
        private FinTSService $fintsService,
        private Logger $logger
    ) {}

    /**
     * Test bank connection
     */
    public function testConnection(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        if (empty($data['bank_code']) || empty($data['fints_url']) || 
            empty($data['username']) || empty($data['password'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Alle Felder müssen ausgefüllt sein'
            ], 400);
        }

        $result = $this->fintsService->testConnection([
            'bank_code' => $data['bank_code'],
            'fints_url' => $data['fints_url'],
            'username' => $data['username'],
            'password' => $data['password']
        ]);

        return $this->jsonResponse($response, $result);
    }

    /**
     * Get accounts from bank
     */
    public function getAccounts(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }

        // Check for existing session
        $session = $this->db->getFinTSSession($bankId);
        $persistedInstance = $session ? $session['session_data'] : null;

        $result = $this->fintsService->getAccounts([
            'bank_code' => $bank['bank_code'],
            'fints_url' => $bank['fints_url'],
            'username' => $bank['username'],
            'password' => $bank['password']
        ], $persistedInstance);

        // Handle TAN requirement
        if (isset($result['needs_tan']) && $result['needs_tan']) {
            // Store session for TAN submission
            $this->db->saveFinTSSession(
                $bankId,
                $result['persisted_instance'],
                null,
                null
            );
            
            // Store action in session for retrieval during TAN submission
            $_SESSION['fints_action_' . $bankId] = $result['persisted_action'];
            
            return $this->jsonResponse($response, [
                'success' => false,
                'needs_tan' => true,
                'tan_request' => $result['tan_request']
            ]);
        }

        // Store accounts in database if successful
        if ($result['success'] && isset($result['accounts'])) {
            foreach ($result['accounts'] as $accountData) {
                $this->db->upsertAccount($bankId, $accountData);
            }
            
            // Update session
            if (isset($result['persisted_instance'])) {
                $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            }
        }

        return $this->jsonResponse($response, $result);
    }

    /**
     * Submit TAN for ongoing action
     */
    public function submitTan(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $data = $request->getParsedBody();
        
        if (empty($data['tan'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'TAN ist erforderlich'
            ], 400);
        }

        $bank = $this->db->getBankById($bankId);
        if (!$bank) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Bank nicht gefunden'
            ], 404);
        }

        $session = $this->db->getFinTSSession($bankId);
        if (!$session) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Keine aktive Sitzung gefunden'
            ], 400);
        }

        // Get persisted action from session
        $persistedAction = $_SESSION['fints_action_' . $bankId] ?? null;
        if (!$persistedAction) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Keine aktive Aktion gefunden'
            ], 400);
        }

        $result = $this->fintsService->submitTan(
            [
                'bank_code' => $bank['bank_code'],
                'fints_url' => $bank['fints_url'],
                'username' => $bank['username'],
                'password' => $bank['password']
            ],
            $session['session_data'],
            $persistedAction,
            $data['tan']
        );

        // Handle another TAN requirement
        if (isset($result['needs_tan']) && $result['needs_tan']) {
            $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            $_SESSION['fints_action_' . $bankId] = $result['persisted_action'];
            
            return $this->jsonResponse($response, [
                'success' => false,
                'needs_tan' => true,
                'tan_request' => $result['tan_request']
            ]);
        }

        // Clean up session
        unset($_SESSION['fints_action_' . $bankId]);

        // Store accounts if we got them
        if ($result['success'] && isset($result['accounts'])) {
            foreach ($result['accounts'] as $accountData) {
                $this->db->upsertAccount($bankId, $accountData);
            }
            
            if (isset($result['persisted_instance'])) {
                $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            }
        }

        return $this->jsonResponse($response, $result);
    }

    /**
     * Helper to create JSON response
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
