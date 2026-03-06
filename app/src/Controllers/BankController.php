<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;
use App\Services\DatabaseService;
use App\Services\FinTSService;

class BankController
{
    public function __construct(
        private Twig $view,
        private DatabaseService $db,
        private FinTSService $fintsService
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $banks = $this->db->getAllBanks();
        
        foreach ($banks as &$bank) {
            $bank['accounts'] = $this->db->getAccountsByBankId($bank['id']);
            $bank['capabilities'] = $this->db->getBankCapabilities($bank['id']);
        }

        return $this->view->render($response, 'banks/index.twig', [
            'banks' => $banks,
            'title' => 'Bankverbindungen'
        ]);
    }

    public function add(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'banks/add.twig', [
            'title' => 'Bank hinzufügen',
            'bank' => []
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        $errors = $this->validateBankData($data);
        
        if (!empty($errors)) {
            return $this->view->render($response, 'banks/add.twig', [
                'title' => 'Bank hinzufügen',
                'bank' => $data,
                'errors' => $errors
            ]);
        }

        try {
            $bankId = $this->db->createBank([
                'name' => $data['name'],
                'bank_code' => $data['bank_code'],
                'fints_url' => $data['fints_url'],
                'username' => $data['username'],
                'password' => $data['password']
            ]);

            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            $url = $routeParser->urlFor('banks.show', ['id' => (string)$bankId]);
            
            return $response
                ->withHeader('Location', $url)
                ->withStatus(302);

        } catch (\Exception $e) {
            return $this->view->render($response, 'banks/add.twig', [
                'title' => 'Bank hinzufügen',
                'bank' => $data,
                'errors' => ['general' => 'Fehler beim Speichern: ' . $e->getMessage()]
            ]);
        }
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $response->withStatus(404);
        }

        $accounts = $this->db->getAccountsByBankId($bankId);
        
        // Get all depots for linking dropdown
        $depots = $this->db->getAllDepots();
        
        // Get TAN session validity info
        $session = $this->db->getFinTSSession($bankId);
        $tanSession = null;
        if ($session) {
            $createdAt = new \DateTime($session['created_at']);
            $expiresAt = new \DateTime($session['expires_at']);
            $now = new \DateTime();
            $remainingDays = max(0, (int) $now->diff($expiresAt)->format('%r%a'));
            $totalDays = (int) $createdAt->diff($expiresAt)->format('%a');
            $elapsedDays = (int) $createdAt->diff($now)->format('%a');
            $progressPercent = $totalDays > 0 ? min(100, round(($elapsedDays / $totalDays) * 100)) : 100;
            
            $tanSession = [
                'created_at' => $createdAt->format('d.m.Y H:i'),
                'expires_at' => $expiresAt->format('d.m.Y H:i'),
                'remaining_days' => $remainingDays,
                'total_days' => $totalDays,
                'progress_percent' => $progressPercent,
                'tan_mode' => $session['tan_mode'] ?? null,
                'tan_medium' => $session['tan_medium'] ?? null,
                'is_valid' => $remainingDays > 0
            ];
        }

        return $this->view->render($response, 'banks/show.twig', [
            'title' => $bank['name'],
            'bank' => $bank,
            'accounts' => $accounts,
            'depots' => $depots,
            'tan_session' => $tanSession
        ]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        
        $this->db->deleteBank($bankId);
        
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('banks.index');
        
        return $response
            ->withHeader('Location', $url)
            ->withStatus(302);
    }

    public function accounts(Request $request, Response $response, array $args): Response
    {
        $bankId = (int) $args['id'];
        $bank = $this->db->getBankById($bankId);
        
        if (!$bank) {
            return $response->withStatus(404);
        }

        $accounts = $this->db->getAccountsByBankId($bankId);

        return $this->view->render($response, 'banks/accounts.twig', [
            'title' => 'Konten - ' . $bank['name'],
            'bank' => $bank,
            'accounts' => $accounts
        ]);
    }

    private function validateBankData(array $data): array
    {
        $errors = [];
        
        if (empty($data['name'])) {
            $errors['name'] = 'Name ist erforderlich';
        }
        
        if (empty($data['bank_code'])) {
            $errors['bank_code'] = 'Bankleitzahl ist erforderlich';
        } elseif (!preg_match('/^\d{8}$/', $data['bank_code'])) {
            $errors['bank_code'] = 'Bankleitzahl muss 8 Ziffern haben';
        }
        
        if (empty($data['fints_url'])) {
            $errors['fints_url'] = 'FinTS-URL ist erforderlich';
        } elseif (!filter_var($data['fints_url'], FILTER_VALIDATE_URL)) {
            $errors['fints_url'] = 'Ungültige URL';
        }
        
        if (empty($data['username'])) {
            $errors['username'] = 'Benutzername ist erforderlich';
        }
        
        if (empty($data['password'])) {
            $errors['password'] = 'Passwort/PIN ist erforderlich';
        }
        
        return $errors;
    }
}
