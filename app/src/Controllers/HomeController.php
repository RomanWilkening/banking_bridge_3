<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\DatabaseService;

class HomeController
{
    public function __construct(
        private Twig $view,
        private DatabaseService $db
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $banks = $this->db->getAllBanks();
        
        // Get accounts for each bank
        foreach ($banks as &$bank) {
            $bank['accounts'] = $this->db->getAccountsByBankId($bank['id']);
            $bank['account_count'] = count($bank['accounts']);
            
            // Calculate total balance (only accounts not excluded from total)
            $totalBalance = 0;
            foreach ($bank['accounts'] as $account) {
                if ($account['balance'] !== null && empty($account['exclude_from_total'])) {
                    $totalBalance += $account['balance'];
                }
            }
            $bank['total_balance'] = $totalBalance;
        }
        
        // Get PayPal accounts
        $paypalAccounts = $this->db->getAllPayPalAccounts();
        
        // Calculate total PayPal balance (only accounts not excluded from total)
        $paypalTotalBalance = 0;
        foreach ($paypalAccounts as $paypal) {
            if ($paypal['balance'] !== null && empty($paypal['exclude_from_total'])) {
                $paypalTotalBalance += $paypal['balance'];
            }
        }
        
        return $this->view->render($response, 'home.twig', [
            'banks' => $banks,
            'paypal_accounts' => $paypalAccounts,
            'paypal_total_balance' => $paypalTotalBalance,
            'title' => 'Dashboard'
        ]);
    }
}
