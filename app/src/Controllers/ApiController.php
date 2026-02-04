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

        // Always start fresh - don't reuse old sessions (they expire quickly)
        // Delete any old session first
        $this->db->deleteFinTSSession($bankId);

        $result = $this->fintsService->getAccounts([
            'bank_code' => $bank['bank_code'],
            'fints_url' => $bank['fints_url'],
            'username' => $bank['username'],
            'password' => $bank['password']
        ], null);

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
            
            // Update session (before removing from result)
            if (isset($result['persisted_instance'])) {
                $this->db->saveFinTSSession($bankId, $result['persisted_instance']);
            }
        }

        // Remove large/non-serializable data before JSON response
        unset($result['persisted_instance']);
        unset($result['persisted_action']);

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

        // Remove large/non-serializable data before JSON response
        unset($result['persisted_instance']);
        unset($result['persisted_action']);

        return $this->jsonResponse($response, $result);
    }

    /**
     * Helper to create JSON response
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        // Remove non-serializable data (like persisted_instance which can be very large)
        unset($data['persisted_instance']);
        
        // Ensure all data is JSON serializable
        $data = $this->sanitizeForJson($data);
        
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        
        if ($json === false) {
            $this->logger->error('JSON encode failed', ['error' => json_last_error_msg()]);
            $json = json_encode([
                'success' => false,
                'message' => 'Interner Fehler bei der Datenverarbeitung'
            ]);
        }
        
        $response->getBody()->write($json);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Recursively sanitize data for JSON encoding
     */
    private function sanitizeForJson($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->sanitizeForJson($value);
            }
            return $result;
        }
        
        if (is_object($data)) {
            // Convert objects to string representation or null
            if (method_exists($data, '__toString')) {
                return (string) $data;
            }
            if ($data instanceof \DateTime || $data instanceof \DateTimeInterface) {
                return $data->format('Y-m-d H:i:s');
            }
            return null;
        }
        
        if (is_resource($data)) {
            return null;
        }
        
        // Handle non-UTF8 strings
        if (is_string($data)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        
        return $data;
    }
}
