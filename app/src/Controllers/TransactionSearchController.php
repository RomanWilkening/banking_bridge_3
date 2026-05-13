<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\DatabaseService;

class TransactionSearchController
{
    public function __construct(
        private Twig $view,
        private DatabaseService $db
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $filters = $this->extractFilters($params);

        $transactions = $this->db->searchAllTransactions($filters, $limit, $offset);
        $total = $this->db->countAllTransactions($filters);
        $totalPages = max(1, (int) ceil($total / $limit));

        // Aggregate sum of matching transactions (best-effort, not paginated).
        // For very large result sets this could be expensive; limit to first
        // 5000 rows so the totals stay informative without runaway queries.
        $aggregateSample = $this->db->searchAllTransactions($filters, 5000, 0);
        $sumIn = 0.0;
        $sumOut = 0.0;
        foreach ($aggregateSample as $tx) {
            $amount = (float) ($tx['amount'] ?? 0);
            if ($amount >= 0) {
                $sumIn += $amount;
            } else {
                $sumOut += $amount;
            }
        }
        $aggregateLimited = count($aggregateSample) >= 5000 && $total > 5000;

        // Data needed by the filter form
        $banks = $this->db->getAllBanks();
        $accounts = $this->db->getAllAccountsWithBank();
        $paypalAccounts = $this->db->getAllPayPalAccounts();

        return $this->view->render($response, 'transactions/search.twig', [
            'title' => 'Transaktionssuche',
            'transactions' => $transactions,
            'filters' => $filters,
            'banks' => $banks,
            'accounts' => $accounts,
            'paypal_accounts' => $paypalAccounts,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $total,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
            ],
            'aggregates' => [
                'sum_in' => $sumIn,
                'sum_out' => $sumOut,
                'sum_net' => $sumIn + $sumOut,
                'limited' => $aggregateLimited,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extractFilters(array $params): array
    {
        $filters = [];

        if (!empty($params['search'])) {
            $filters['search'] = trim((string) $params['search']);
        }
        if (!empty($params['date_from'])) {
            $filters['date_from'] = (string) $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $filters['date_to'] = (string) $params['date_to'];
        }
        if (isset($params['amount_min']) && $params['amount_min'] !== '') {
            $filters['amount_min'] = (string) $params['amount_min'];
        }
        if (isset($params['amount_max']) && $params['amount_max'] !== '') {
            $filters['amount_max'] = (string) $params['amount_max'];
        }
        if (!empty($params['direction']) && in_array($params['direction'], ['in', 'out'], true)) {
            $filters['direction'] = (string) $params['direction'];
        }
        if (!empty($params['source']) && in_array($params['source'], ['bank', 'paypal'], true)) {
            $filters['source'] = (string) $params['source'];
        }
        if (!empty($params['bank_id'])) {
            $filters['bank_id'] = (int) $params['bank_id'];
        }
        if (!empty($params['account_id'])) {
            $filters['account_id'] = (int) $params['account_id'];
        }
        if (!empty($params['paypal_account_id'])) {
            $filters['paypal_account_id'] = (int) $params['paypal_account_id'];
        }

        return $filters;
    }
}
