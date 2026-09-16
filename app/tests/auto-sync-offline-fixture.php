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
            return ['success' => true, 'balance' => 0, 'transactions_new' => 2];
        }
    }
}

namespace {
    if (getenv('FINTS_OFFLINE_TEST') !== '1') {
        throw new RuntimeException('Only for the offline regression test');
    }
    require __DIR__ . '/../bin/auto-sync.php';
}
