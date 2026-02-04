<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

// Start session for TAN handling
session_start();

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
$app->addErrorMiddleware(true, true, true);

// Add Body Parsing Middleware
$app->addBodyParsingMiddleware();

// Load Routes
(require __DIR__ . '/../config/routes.php')($app);

$app->run();
