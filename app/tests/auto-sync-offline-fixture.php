<?php
declare(strict_types=1);

namespace App\Services {
    // This fixture deliberately shadows the network adapter before Composer is loaded.
    final class PayPalService
    {
        public function __construct($logger, private $db) {}
        public function syncAccount(int $id): array
        {
            $this->db->setSetting('offline_paypal_calls', (string) ((int) $this->db->getSetting('offline_paypal_calls', '0') + 1));
            if ($this->db->getSetting('offline_paypal_partial') === '1') {
                return ['success' => false, 'partial' => true, 'balance' => 0, 'transactions_new' => 0,
                    'operations' => ['balance' => ['success' => true], 'transactions' => ['success' => false]]];
            }
            return ['success' => true, 'balance' => 0, 'transactions_new' => 2];
        }
    }
}

namespace {
    if (getenv('FINTS_OFFLINE_TEST') !== '1') {
        throw new RuntimeException('Only for the offline regression test');
    }
    if (getenv('FINTS_API_OFFLINE_TEST') === '1') {
        require __DIR__ . '/../vendor/autoload.php';
        $db = new \App\Services\DatabaseService(getenv('DATA_PATH') . '/banking.db');
        $logger = new \Monolog\Logger('offline-api');
        $api = new \App\Controllers\ApiController($db, new \App\Services\FinTSService($logger),
            new \App\Services\MqttService($logger, $db), $logger);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/auto-sync/run');
        echo (string) $api->runAutoSync($request, new \Slim\Psr7\Response())->getBody();
    } else {
        require __DIR__ . '/../bin/auto-sync.php';
    }
}
