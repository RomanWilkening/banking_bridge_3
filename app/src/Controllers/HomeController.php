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
        $totalBalances = [];
        
        // Get accounts for each bank
        foreach ($banks as &$bank) {
            $bank['accounts'] = $this->db->getAccountsByBankId($bank['id']);
            $bank['account_count'] = count($bank['accounts']);
            
            // Calculate total balance (only accounts not excluded from total)
            $bank['authorization'] = $this->db->getBankAuthorizationState((int) $bank['id']);
            $balances = [];
            $bank['oldest_balance_date'] = null;
            $bank['missing_balance_count'] = 0;
            foreach ($bank['accounts'] as $account) {
                if ($account['balance'] === null || empty($account['balance_date'])) {
                    $bank['missing_balance_count']++;
                } elseif ($bank['oldest_balance_date'] === null || $account['balance_date'] < $bank['oldest_balance_date']) {
                    $bank['oldest_balance_date'] = $account['balance_date'];
                }
                if ($account['balance'] !== null && empty($account['exclude_from_total'])) {
                    $currency = strtoupper(trim($account['currency'] ?? '')) ?: 'Unbekannt';
                    $balances[$currency] = ($balances[$currency] ?? 0) + $account['balance'];
                    $totalBalances[$currency] = ($totalBalances[$currency] ?? 0) + $account['balance'];
                }
            }
            ksort($balances);
            $bank['total_balances'] = $balances;
            if ($bank['oldest_balance_date'] !== null) {
                $bank['oldest_balance_date'] = (new \DateTimeImmutable($bank['oldest_balance_date'], new \DateTimeZone('UTC')))->format(DATE_ATOM);
            }
        }
        unset($bank);
        
        // Get PayPal accounts
        $paypalAccounts = $this->db->getAllPayPalAccounts();
        
        // Calculate total PayPal balance (only accounts not excluded from total)
        $paypalTotalBalances = [];
        foreach ($paypalAccounts as &$paypal) {
            if (!empty($paypal['last_sync'])) {
                $paypal['last_sync'] = (new \DateTimeImmutable($paypal['last_sync'], new \DateTimeZone('UTC')))->format(DATE_ATOM);
            }
            if ($paypal['balance'] !== null && empty($paypal['exclude_from_total'])) {
                $currency = strtoupper(trim($paypal['currency'] ?? '')) ?: 'Unbekannt';
                $paypalTotalBalances[$currency] = ($paypalTotalBalances[$currency] ?? 0) + $paypal['balance'];
                $totalBalances[$currency] = ($totalBalances[$currency] ?? 0) + $paypal['balance'];
            }
            unset($paypal);
        }
        ksort($paypalTotalBalances);
        ksort($totalBalances);
        
        return $this->view->render($response, 'home.twig', [
            'banks' => $banks,
            'paypal_accounts' => $paypalAccounts,
            'paypal_total_balances' => $paypalTotalBalances,
            'total_balances' => $totalBalances,
            'title' => 'Dashboard'
        ]);
    }
}
