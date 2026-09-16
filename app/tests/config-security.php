<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Middleware\SessionCsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$handler = new class implements RequestHandlerInterface {
    public int $calls = 0;
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;
        return new Response(204);
    }
};
$middleware = new SessionCsrfMiddleware();
$factory = new ServerRequestFactory();
$_SESSION = ['csrf_token' => bin2hex(random_bytes(32))];
$checks = 0;
$check = function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    $request = $factory->createServerRequest($method, 'http://localhost/settings');
    $calls = $handler->calls;
    $check($middleware->process($request, $handler)->getStatusCode() === 403, "$method without token accepted");
    $check($handler->calls === $calls, "$method reached handler without token");
    $check($middleware->process($request->withParsedBody(['csrf_token' => ['invalid']]), $handler)->getStatusCode() === 403, 'Array token accepted');
    $check($middleware->process($request->withHeader('X-CSRF-Token', 'invalid'), $handler)->getStatusCode() === 403, 'Wrong token accepted');
    $check($middleware->process($request->withHeader('X-CSRF-Token', $_SESSION['csrf_token']), $handler)->getStatusCode() === 204, 'Valid API token rejected');
    $check($middleware->process($request->withParsedBody(['csrf_token' => $_SESSION['csrf_token']]), $handler)->getStatusCode() === 204, 'Valid form token rejected');
}
$check($middleware->process($factory->createServerRequest('GET', 'http://localhost/api/v1/accounts'), $handler)->getStatusCode() === 204, 'Read-only API rejected');
$db = new class extends \App\Services\DatabaseService {
    public array $settings = ['mqtt_password' => 'synthetic-fixture-value'];
    public function __construct() {}
    public function getSetting(string $key, ?string $default = null): ?string
    {
        return $this->settings[$key] ?? $default;
    }
    public function setSetting(string $key, string $value): bool
    {
        $this->settings[$key] = $value;
        return true;
    }
};
$controller = new \App\Controllers\SettingsController(\Slim\Views\Twig::create(__DIR__ . '/../templates'), $db);
$app = \Slim\Factory\AppFactory::create();
$app->get('/settings', fn($request, $response) => $response)->setName('settings');
$app->post('/settings', [$controller, 'save']);
$app->addRoutingMiddleware();
$app->add($middleware);
$app->addBodyParsingMiddleware();
$settingsRequest = $factory->createServerRequest('POST', 'http://localhost/settings')
    ->withParsedBody(['mqtt_enabled' => '1', 'mqtt_host' => 'broker.example.invalid', 'mqtt_password' => '']);
$before = $db->settings;
$check($app->handle($settingsRequest)->getStatusCode() === 403, 'Settings accepted without token');
$check($db->settings === $before, 'Untrusted request modified MQTT settings');
$settingsRequest = $settingsRequest->withHeader('X-CSRF-Token', $_SESSION['csrf_token']);
$check($app->handle($settingsRequest)->getStatusCode() === 302, 'Authorized settings save rejected');
$check($db->settings['mqtt_password'] === 'synthetic-fixture-value', 'Blank field erased stored password');
foreach ([
    ['mqtt_port' => '0'],
    ['mqtt_port' => '65536'],
    ['auto_sync_interval' => '-1'],
    ['mqtt_auto_publish_interval' => '0'],
    ['mqtt_topic_prefix' => 'banking/#'],
] as $invalid) {
    $before = $db->settings;
    $check($app->handle($settingsRequest->withParsedBody($invalid))->getStatusCode() === 400, 'Invalid setting accepted');
    $check($db->settings === $before, 'Invalid setting partially saved');
}
$check($app->handle($settingsRequest->withParsedBody(['mqtt_password_clear' => '1']))->getStatusCode() === 302, 'Explicit password removal rejected');
$check($db->settings['mqtt_password'] === '', 'Explicit password removal failed');
putenv('APP_DEBUG=false');
$config = require __DIR__ . '/../config/container.php';
$check($config['settings']['displayErrorDetails'] === false, 'Production error detail enabled');
putenv('APP_DEBUG=true');
$config = require __DIR__ . '/../config/container.php';
$check($config['settings']['displayErrorDetails'] === true, 'Explicit debug not honored');
putenv('APP_DEBUG');
echo "$checks configuration/security checks passed\n";
