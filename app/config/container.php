<?php
declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use App\Services\DatabaseService;
use App\Services\FinTSService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

return [
    'settings' => [
        'displayErrorDetails' => true,
        'database' => [
            'path' => '/data/banking.db'
        ],
    ],

    Twig::class => function (ContainerInterface $container) {
        return Twig::create(__DIR__ . '/../templates', ['cache' => false]);
    },

    Logger::class => function (ContainerInterface $container) {
        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler('/data/app.log', Logger::DEBUG));
        return $logger;
    },

    DatabaseService::class => function (ContainerInterface $container) {
        $settings = $container->get('settings');
        return new DatabaseService($settings['database']['path']);
    },

    FinTSService::class => function (ContainerInterface $container) {
        $logger = $container->get(Logger::class);
        return new FinTSService($logger);
    },
];
