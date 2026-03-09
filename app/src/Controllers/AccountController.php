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
            $linkedAccounts = $this->db->getLinkedAccounts($accountId);
            $totals = $this->db->getDepotTotalValueWithLinked($accountId);
            
            // Calculate totals
            $totalProfitLoss = 0;
            foreach ($holdings as $holding) {
                $totalProfitLoss += $holding['profit_loss'] ?? 0;
            }
            
            // Get display name
            $displayName = $account['custom_name'] ?? $account['account_name'] ?? 'Depot';
            
            return $this->view->render($response, 'accounts/depot.twig', [
                'title' => $displayName,
                'account' => $account,
                'bank' => $bank,
                'holdings' => $holdings,
                'linked_accounts' => $linkedAccounts,
                'total_value' => $totals['total_value'],
                'securities_value' => $totals['securities_value'],
                'linked_accounts_value' => $totals['linked_accounts_value'],
                'total_profit_loss' => $totalProfitLoss,
                'holdings_count' => count($holdings),
                'linked_accounts_count' => count($linkedAccounts)
            ]);
        }
        
        // For regular accounts, show transactions
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        // Build filter array from query params
        $filters = [];
        if (!empty($params['search'])) {
            $filters['search'] = trim($params['search']);
        }
        if (!empty($params['date_from'])) {
            $filters['date_from'] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $filters['date_to'] = $params['date_to'];
        }
        if (isset($params['amount_min']) && $params['amount_min'] !== '') {
            $filters['amount_min'] = $params['amount_min'];
        }
        if (isset($params['amount_max']) && $params['amount_max'] !== '') {
            $filters['amount_max'] = $params['amount_max'];
        }

        $transactions = $this->db->getTransactionsByAccountId($accountId, $limit, $offset, $filters);
        $totalTransactions = $this->db->getTransactionCount($accountId, $filters);
        $totalPages = max(1, ceil($totalTransactions / $limit));
        
        // Get display name
        $displayName = $account['custom_name'] ?? $account['account_name'] ?? 'Konto';

        return $this->view->render($response, 'accounts/show.twig', [
            'title' => $displayName,
            'account' => $account,
            'bank' => $bank,
            'transactions' => $transactions,
            'filters' => $filters,
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
