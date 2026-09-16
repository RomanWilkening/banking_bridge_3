<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\HomeController;
use App\Services\DatabaseService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final class UiTestDatabase extends DatabaseService
{
    public function __construct() {}
    public function getAllBanks(): array
    {
        return [
            ['id' => 1, 'name' => 'First </script> bank', 'bank_code' => '12345678', 'password' => 'must-not-be-rendered'],
            ['id' => 2, 'name' => 'Second connection', 'bank_code' => '12345678'],
            ['id' => 3, 'name' => 'Empty connection', 'bank_code' => '87654321'],
        ];
    }
    public function getAccountsByBankId(int $bankId): array
    {
        if ($bankId === 3) return [];
        if ($bankId === 2) return [['balance' => 5, 'currency' => 'CHF', 'balance_date' => '2026-01-01 10:00:00']];
        return [
            ['balance' => 100, 'currency' => 'EUR', 'balance_date' => '2026-02-01 10:00:00'],
            ['balance' => 200, 'currency' => 'USD', 'balance_date' => '2026-03-01 10:00:00'],
            ['balance' => 9999, 'currency' => 'EUR', 'balance_date' => null, 'exclude_from_total' => 1],
            ['balance' => null, 'currency' => 'JPY', 'balance_date' => null],
        ];
    }
    public function getBankAuthorizationState(int $bankId): array
    {
        return ['bank_id' => $bankId, 'status' => [1 => 'required', 2 => 'error', 3 => 'unknown'][$bankId],
            'reason' => null, 'authenticated_at' => null, 'required_at' => null, 'expires_at' => null];
    }
    public function getAllPayPalAccounts(): array
    {
        return [
            ['id' => 1, 'name' => 'PayPal EUR', 'balance' => 10, 'currency' => 'EUR', 'last_sync' => '2026-02-01 10:00:00'],
            ['id' => 2, 'name' => 'PayPal USD', 'balance' => 20, 'currency' => 'USD', 'last_sync' => null],
            ['id' => 3, 'name' => 'Excluded', 'balance' => 8888, 'currency' => 'EUR', 'exclude_from_total' => 1],
        ];
    }
}

function uiCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

date_default_timezone_set('Pacific/Honolulu');
$view = Twig::create(__DIR__ . '/../templates', ['cache' => false]);
$view->getEnvironment()->addGlobal('csrf_token', 'test-csrf-token');
$templates = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../templates'));
$compiled = 0;
foreach ($templates as $file) {
    if ($file->getExtension() !== 'twig') continue;
    $view->getEnvironment()->load(substr($file->getPathname(), strlen(__DIR__ . '/../templates/')));
    $compiled++;
}
$db = new UiTestDatabase();
$controller = new HomeController($view, $db);
$response = $controller->index((new ServerRequestFactory())->createServerRequest('GET', '/'), new Response());
$home = (string) $response->getBody();
foreach (['110,00 EUR', '220,00 USD', '5,00 CHF', '100,00 EUR', '200,00 USD',
    'Bankfreigabe erforderlich', 'Fehler beim Bankzugang', 'Freigabestatus unbekannt',
    '01.02.2026 10:00 UTC', 'Second connection', 'Keine Salden vorhanden'] as $expected) {
    uiCheck(str_contains($home, $expected), 'Dashboard missing: ' . $expected);
}
uiCheck(!str_contains($home, 'must-not-be-rendered'), 'Dashboard leaked bank credentials');
uiCheck(!str_contains($home, '/decoupled'), 'Dashboard must not poll TAN requests');
uiCheck(!str_contains($home, 'First </script> bank'), 'Unsafe bank name in script');
$bank = $db->getAllBanks()[0] + ['authorization' => $db->getBankAuthorizationState(1), 'created_at' => '2026-01-01'];
$account = ['id' => 10, 'bank_id' => 1, 'account_name' => 'Test account', 'account_type' => 'giro',
    'currency' => 'EUR', 'balance' => 100, 'background_sync_enabled' => 0];
$fixtures = ['home' => $home];
$fixtures['bank'] = $view->fetch('banks/show.twig', ['bank' => $bank, 'accounts' => [$account], 'depots' => []]);
$fixtures['account'] = $view->fetch('accounts/show.twig', ['bank' => $bank, 'account' => $account,
    'filters' => [], 'transactions' => [], 'pagination' => ['total_items' => 0, 'total_pages' => 1]]);
$fixtures['depot'] = $view->fetch('accounts/depot.twig', ['bank' => $bank, 'account' => $account,
    'holdings' => [], 'linked_accounts' => [], 'holdings_count' => 0, 'linked_accounts_count' => 0]);
uiCheck(str_contains($fixtures['bank'], 'Bankzugang freigeben'), 'Missing explicit authorization action');
uiCheck(str_contains($fixtures['bank'], 'backgroundSyncToggle(10, 0)'), 'Background exclusion not reflected');
uiCheck(!str_contains($fixtures['bank'], 'tan-manual-approval'), 'Legacy TAN toggle still present');
uiCheck(!str_contains($fixtures['bank'], '90 Tage gültig'), 'False SCA guarantee remains');
uiCheck(str_contains($fixtures['bank'], 'Lokale Freigabefrist (keine Bankgarantie)'), 'Authorization policy expiry must not claim bank guarantee');
$settings = $view->fetch('settings.twig', ['settings' => ['mqtt_password' => 'must-not-be-rendered']]);
uiCheck(!str_contains($settings, 'must-not-be-rendered'), 'Settings template rendered stored MQTT password');
uiCheck(str_contains($settings, 'name="mqtt_password_clear"'), 'Missing explicit password clear checkbox');
uiCheck(str_contains($settings, 'name="csrf_token"'), 'Settings form missing CSRF token');
foreach (['account', 'depot'] as $page) {
    uiCheck(!str_contains($fixtures[$page], '/decoupled'), $page . ' still polls TAN');
    uiCheck(!str_contains($fixtures[$page], 'submitTan'), $page . ' still accepts TAN');
    uiCheck(str_contains($fixtures[$page], 'ausgeschlossen'), $page . ' background flag missing');
}
if (in_array('--fixtures', $argv, true)) {
    echo json_encode($fixtures, JSON_THROW_ON_ERROR);
} else {
    echo "PASS: $compiled Twig templates compile; dashboard currency, connection isolation, UTC, and safe UI checks\n";
}
