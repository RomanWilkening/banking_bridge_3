<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\DatabaseService;

class AccountController
{
    public function __construct(
        private Twig $view,
        private DatabaseService $db
    ) {}

    public function show(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $account = $this->db->getAccountById($accountId);
        
        if (!$account) {
            return $response->withStatus(404);
        }

        $bank = $this->db->getBankById($account['bank_id']);
        if (!$bank) {
            return $response->withStatus(404);
        }

        $isDepot = ($account['account_type'] ?? '') === 'depot';
        
        if ($isDepot) {
            // For depots, show holdings
            $holdings = $this->db->getSecuritiesHoldings($accountId);
            $totalValue = $this->db->getDepotTotalValue($accountId);
            
            // Calculate totals
            $totalProfitLoss = 0;
            foreach ($holdings as $holding) {
                $totalProfitLoss += $holding['profit_loss'] ?? 0;
            }
            
            return $this->view->render($response, 'accounts/depot.twig', [
                'title' => $account['account_name'] ?? 'Depot',
                'account' => $account,
                'bank' => $bank,
                'holdings' => $holdings,
                'total_value' => $totalValue,
                'total_profit_loss' => $totalProfitLoss,
                'holdings_count' => count($holdings)
            ]);
        }
        
        // For regular accounts, show transactions
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $transactions = $this->db->getTransactionsByAccountId($accountId, $limit, $offset);
        $totalTransactions = $this->db->getTransactionCount($accountId);
        $totalPages = max(1, ceil($totalTransactions / $limit));

        return $this->view->render($response, 'accounts/show.twig', [
            'title' => $account['account_name'] ?? 'Konto',
            'account' => $account,
            'bank' => $bank,
            'transactions' => $transactions,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $totalTransactions,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
            ]
        ]);
    }
}
