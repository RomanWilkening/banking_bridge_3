<?php
declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use App\Services\DatabaseService;
use App\Services\FinTSService;
use App\Services\MqttService;
use App\Services\PayPalService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

return [
    'settings' => [
        'displayErrorDetails' => true,
        'database' => [
            'path' => '/data/banking.db'
        ],
    ],

    'view' => function (ContainerInterface $container) {
        return Twig::create(__DIR__ . '/../templates', ['cache' => false]);
    },

    Twig::class => function (ContainerInterface $container) {
        return $container->get('view');
    },

    Logger::class => function (ContainerInterface $container) {
        $logger = new Logger('app');
        // Log to file
        $logger->pushHandler(new StreamHandler('/data/app.log', Logger::DEBUG));
        // Also log to stdout (for docker-compose logs)
        $logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
        return $logger;
    },

    DatabaseService::class => function (ContainerInterface $container) {
        $settings = $container->get('settings');
        return new DatabaseService($settings['database']['path']);
    },

    FinTSService::class => function (ContainerInterface $container) {
        $logger = $container->get(Logger::class);
        $db = $container->get(DatabaseService::class);
        
        $service = new FinTSService($logger);
        $service->setProductId($db->getSetting('fints_product_id'));
        
        return $service;
    },
    
    MqttService::class => function (ContainerInterface $container) {
        $logger = $container->get(Logger::class);
        $db = $container->get(DatabaseService::class);
        return new MqttService($logger, $db);
    },
    
    PayPalService::class => function (ContainerInterface $container) {
        $logger = $container->get(Logger::class);
        $db = $container->get(DatabaseService::class);
        return new PayPalService($logger, $db);
    },
];
