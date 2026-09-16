<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

// Start session for TAN handling
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
session_start();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

// Build Container
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

// Create App
AppFactory::setContainer($container);
$app = AppFactory::create();

// Add Twig Middleware
$app->add(TwigMiddleware::createFromContainer($app));

// Add Error Middleware
$app->addErrorMiddleware($container->get('settings')['displayErrorDetails'], true, false);

// Add Body Parsing Middleware
$app->add(new \App\Middleware\SessionCsrfMiddleware());
$app->addBodyParsingMiddleware();

// Load Routes
(require __DIR__ . '/../config/routes.php')($app);

$app->run();
