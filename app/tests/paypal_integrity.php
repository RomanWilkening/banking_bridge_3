<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\DatabaseService;
use App\Services\PayPalService;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

final class FixturePayPalService extends PayPalService
{
    public array $responses = [];
    public array $requests = [];

    protected function call(array $credentials, string $method, array $params = []): array
    {
        $this->requests[$method] = $params;
        $response = $this->responses[$method] ?? throw new RuntimeException('Unexpected API operation');
        if ($response instanceof Throwable) {
            throw $response;
        }
        return $response;
    }
}

$checks = 0;
function checkPayPal(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$directory = sys_get_temp_dir() . '/banking-paypal-integrity-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Unable to create isolated PayPal fixture directory');
}
$path = $directory . '/test.sqlite';
$oldTimezone = date_default_timezone_get();
try {
    date_default_timezone_set('Pacific/Auckland');
    $db = new DatabaseService($path);
    $id = $db->createPayPalAccount(['name' => 'Fixture', 'api_username' => 'fixture',
        'api_password' => 'fixture', 'api_signature' => 'fixture']);
    $db->updatePayPalAccountBalance($id, 123.5, '2026-01-01 00:00:00', 'GBP');
    $logger = new Logger('fixture');
    $logger->pushHandler(new NullHandler());
    $service = new FixturePayPalService($logger, $db);
    $failure = ['success' => false, 'error' => 'Fixture API unavailable'];
    $emptyTransactions = ['success' => true, 'data' => ['ACK' => 'Success']];
    $balance = ['success' => true, 'data' => ['L_AMT0' => '0.00', 'L_CURRENCYCODE0' => 'USD',
        'L_AMT1' => '45.00', 'L_CURRENCYCODE1' => 'EUR']];
    $transactions = ['success' => true, 'data' => ['ACK' => 'Success', 'L_TRANSACTIONID0' => 'fixture-tx',
        'L_TIMESTAMP0' => '2026-09-01T00:30:00+02:00', 'L_AMT0' => '-10.25',
        'L_CURRENCYCODE0' => 'USD', 'L_NAME0' => 'Merchant']];

    $service->responses = ['GetBalance' => $failure, 'TransactionSearch' => $failure];
    $result = $service->syncAccount($id);
    checkPayPal(!$result['success'] && !$result['partial'] && count($result['errors']) === 2,
        'Two bank failures cannot report successful sync');
    checkPayPal(!$result['operations']['balance']['success'] && !$result['operations']['transactions']['success'],
        'Failures report per-operation status');
    $stored = $db->getPayPalAccountById($id);
    checkPayPal((float) $stored['balance'] === 123.5 && $stored['currency'] === 'GBP'
        && $stored['last_sync'] === '2026-01-01 00:00:00', 'Failed balance preserves value, currency and freshness');

    $service->responses['TransactionSearch'] = $transactions;
    $result = $service->syncAccount($id);
    checkPayPal(!$result['success'] && $result['partial'] && $result['transactions_new'] === 1
        && isset($result['errors']['balance']), 'Successful transactions saved despite balance failure');
    checkPayPal((float) $db->getPayPalAccountById($id)['balance'] === 123.5, 'Partial sync does not zero balance');
    checkPayPal($db->getPayPalTransactions($id)[0]['timestamp'] === '2026-08-31 22:30:00', 'PayPal timestamps stored in UTC');
    checkPayPal($service->syncAccount($id)['transactions_new'] === 0, 'PayPal transaction replay is idempotent');

    $service->responses = ['GetBalance' => $balance, 'TransactionSearch' => $failure];
    $result = $service->syncAccount($id);
    checkPayPal(!$result['success'] && $result['partial'] && isset($result['errors']['transactions']),
        'Transaction failure reported after successful balance');
    $stored = $db->getPayPalAccountById($id);
    checkPayPal((float) $stored['balance'] === 0.0 && $stored['currency'] === 'USD',
        'Primary zero USD balance saved without preferring secondary EUR');
    checkPayPal(abs(strtotime($stored['last_sync'] . ' UTC') - time()) <= 2, 'Balance freshness timestamp uses UTC');
    checkPayPal($db->getPayPalTransactionCount($id) === 1, 'Failed transaction retrieval preserves existing rows');

    $service->responses['TransactionSearch'] = $emptyTransactions;
    $result = $service->syncAccount($id);
    checkPayPal($result['success'] && !$result['partial'] && $result['errors'] === []
        && $result['operations']['balance']['success'] && $result['operations']['transactions']['success'],
        'Both operations must succeed for aggregate success');
    checkPayPal($db->getPayPalTransactionCount($id) === 1, 'Empty successful transaction window does not delete history');
    $db->updatePayPalAccountBalance($id, 90, '2026-01-01 00:00:00', 'GBP');
    foreach ([[], ['L_AMT0' => 'invalid', 'L_CURRENCYCODE0' => 'EUR'], ['L_AMT0' => '1'],
        ['L_AMT0' => 'INF', 'L_CURRENCYCODE0' => 'EUR']] as $invalidBalance) {
        $service->responses['GetBalance'] = ['success' => true, 'data' => $invalidBalance];
        $result = $service->syncAccount($id);
        $stored = $db->getPayPalAccountById($id);
        checkPayPal(!$result['success'] && $result['partial'] && (float) $stored['balance'] === 90.0
            && $stored['currency'] === 'GBP' && $stored['last_sync'] === '2026-01-01 00:00:00',
            'Absent or invalid balance cannot overwrite good monetary data');
    }

    $service->responses = ['GetBalance' => new RuntimeException('Fixture transport failed'),
        'TransactionSearch' => $transactions];
    $result = $service->syncAccount($id);
    checkPayPal(!$result['success'] && $result['partial'] && $result['operations']['transactions']['success'],
        'Thrown bank failures isolated to their operation');
    $service->responses = ['GetBalance' => $balance, 'TransactionSearch' => $transactions];
    $start = new DateTime('2026-09-01 10:00:00', new DateTimeZone('Europe/Berlin'));
    $end = new DateTime('2026-09-02 10:00:00', new DateTimeZone('Europe/Berlin'));
    $service->searchTransactions([], $start, $end);
    checkPayPal($service->requests['TransactionSearch']['STARTDATE'] === '2026-09-01T08:00:00Z'
        && $service->requests['TransactionSearch']['ENDDATE'] === '2026-09-02T08:00:00Z',
        'Search boundaries are converted to UTC before adding Z');
    checkPayPal($start->getTimezone()->getName() === 'Europe/Berlin', 'Caller DateTime objects not mutated');
    $service->testCredentials([]);
    checkPayPal(abs(strtotime($service->requests['TransactionSearch']['ENDDATE']) - time()) <= 2,
        'Credential test sends actual UTC time');

    $warning = $transactions;
    $warning['data']['ACK'] = 'SuccessWithWarning';
    $warning['data']['L_ERRORCODE0'] = '11002';
    $service->responses['TransactionSearch'] = $warning;
    checkPayPal(!$service->syncAccount($id)['operations']['transactions']['success'],
        'Truncated PayPal search cannot report complete success');
    $malformed = $transactions;
    unset($malformed['data']['L_AMT0']);
    $service->responses['TransactionSearch'] = $malformed;
    checkPayPal(!$service->syncAccount($id)['operations']['transactions']['success'],
        'Missing transaction amount cannot create a zero payment');

    $batch = $transactions;
    $batch['data']['L_TRANSACTIONID0'] = 'before-failure';
    $batch['data']['L_TRANSACTIONID1'] = 'reject-insert';
    $batch['data']['L_AMT1'] = '3';
    $batch['data']['L_CURRENCYCODE1'] = 'USD';
    $service->responses['TransactionSearch'] = $batch;
    $db->getPdo()->exec("CREATE TRIGGER reject_paypal_insert BEFORE INSERT ON paypal_transactions
        WHEN NEW.transaction_id = 'reject-insert' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    $result = $service->syncAccount($id);
    checkPayPal(!$result['success'] && $result['partial'] && !$result['operations']['transactions']['success']
        && $result['transactions_new'] === 0, 'Database failure appears in per-operation sync status');
    checkPayPal($db->getPayPalTransactionCount($id) === 1, 'Failed PayPal transaction batch rolls back every new row');
    $db->getPdo()->exec('DROP TRIGGER reject_paypal_insert');
    checkPayPal(!$service->syncAccount(999)['success'], 'Missing account reports failure');
    $db->deletePayPalAccount($id);
    checkPayPal($db->getPayPalTransactionCount($id) === 0, 'PayPal account deletion cascades transactions');
    echo "paypal_integrity: {$checks} checks passed\n";
} finally {
    date_default_timezone_set($oldTimezone);
    unset($service, $db);
    foreach (glob($directory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
}
