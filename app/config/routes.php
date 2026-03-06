<?php
declare(strict_types=1);

use Slim\App;
use App\Controllers\HomeController;
use App\Controllers\BankController;
use App\Controllers\ApiController;
use App\Controllers\PayPalController;

return function (App $app) {
    // Web Routes
    $app->get('/', [HomeController::class, 'index'])->setName('home');
    $app->get('/banks', [BankController::class, 'index'])->setName('banks.index');
    $app->get('/banks/add', [BankController::class, 'add'])->setName('banks.add');
    $app->post('/banks/add', [BankController::class, 'store'])->setName('banks.store');
    $app->get('/banks/{id}', [BankController::class, 'show'])->setName('banks.show');
    $app->post('/banks/{id}/delete', [BankController::class, 'delete'])->setName('banks.delete');
    $app->get('/banks/{id}/accounts', [BankController::class, 'accounts'])->setName('banks.accounts');
    
    // Account Routes
    $app->get('/accounts/{id}', [\App\Controllers\AccountController::class, 'show'])->setName('accounts.show');
    
    // PayPal Routes
    $app->get('/paypal', [PayPalController::class, 'index'])->setName('paypal.index');
    $app->get('/paypal/add', [PayPalController::class, 'add'])->setName('paypal.add');
    $app->post('/paypal/add', [PayPalController::class, 'store'])->setName('paypal.store');
    $app->get('/paypal/{id}', [PayPalController::class, 'show'])->setName('paypal.show');
    $app->post('/paypal/{id}/delete', [PayPalController::class, 'delete'])->setName('paypal.delete');
    
    // Settings Routes
    $app->get('/settings', [\App\Controllers\SettingsController::class, 'index'])->setName('settings');
    $app->post('/settings', [\App\Controllers\SettingsController::class, 'save'])->setName('settings.save');
    
    // API Routes
    $app->post('/api/banks/test', [ApiController::class, 'testConnection'])->setName('api.banks.test');
    $app->get('/api/banks/{id}/accounts', [ApiController::class, 'getAccounts'])->setName('api.banks.accounts');
    $app->post('/api/banks/{id}/balances', [ApiController::class, 'syncBalances'])->setName('api.banks.balances');
    $app->post('/api/banks/{id}/sync-all', [ApiController::class, 'syncAll'])->setName('api.banks.syncAll');
    $app->get('/api/banks/{id}/activity-log', [ApiController::class, 'getActivityLog'])->setName('api.banks.activityLog');
    $app->get('/api/banks/{id}/capabilities', [ApiController::class, 'getBankCapabilities'])->setName('api.banks.capabilities');
    $app->post('/api/banks/{id}/tan', [ApiController::class, 'submitTan'])->setName('api.banks.tan');
    $app->post('/api/banks/{id}/decoupled', [ApiController::class, 'checkDecoupled'])->setName('api.banks.decoupled');
    $app->post('/api/banks/{id}/reset-session', [ApiController::class, 'resetSession'])->setName('api.banks.resetSession');
    $app->get('/api/accounts/{id}/transactions', [ApiController::class, 'getTransactions'])->setName('api.accounts.transactions');
    $app->post('/api/accounts/{id}/sync', [ApiController::class, 'syncAccount'])->setName('api.accounts.sync');
    $app->post('/api/accounts/{id}/depot', [ApiController::class, 'syncDepotHoldings'])->setName('api.accounts.depot');
    $app->get('/api/accounts/{id}/holdings', [ApiController::class, 'getDepotHoldings'])->setName('api.accounts.holdings');
    
    // Auto-sync API
    $app->post('/api/auto-sync/run', [ApiController::class, 'runAutoSync'])->setName('api.autosync.run');
    $app->get('/api/auto-sync/status', [ApiController::class, 'getAutoSyncStatus'])->setName('api.autosync.status');
    
    // Cron Status API
    $app->get('/api/cron/status', [ApiController::class, 'getCronStatus'])->setName('api.cron.status');
    
    // MQTT API
    $app->post('/api/mqtt/test', [ApiController::class, 'testMqtt'])->setName('api.mqtt.test');
    $app->post('/api/mqtt/publish', [ApiController::class, 'publishMqtt'])->setName('api.mqtt.publish');
    $app->get('/api/mqtt/accounts', [ApiController::class, 'getMqttAccounts'])->setName('api.mqtt.accounts');
    $app->post('/api/accounts/{id}/mqtt-export', [ApiController::class, 'setAccountMqttExport'])->setName('api.accounts.mqttExport');
    $app->post('/api/accounts/{id}/tan-manual-approval', [ApiController::class, 'setAccountTanManualApproval'])->setName('api.accounts.tanManualApproval');
    
    // TAN Session Info
    $app->get('/api/banks/{id}/tan-session', [ApiController::class, 'getTanSessionInfo'])->setName('api.banks.tanSession');
    
    // Public Depot API (for external services)
    $app->get('/api/v1/depots', [ApiController::class, 'listDepots'])->setName('api.v1.depots');
    $app->get('/api/v1/depots/{id}', [ApiController::class, 'getDepot'])->setName('api.v1.depot');
    $app->get('/api/v1/depots/{id}/holdings', [ApiController::class, 'listDepotHoldings'])->setName('api.v1.depot.holdings');
    $app->get('/api/v1/holdings', [ApiController::class, 'listAllHoldings'])->setName('api.v1.holdings');
    
    // Rename & Link API
    $app->patch('/api/banks/{id}', [ApiController::class, 'updateBank'])->setName('api.banks.update');
    $app->patch('/api/accounts/{id}', [ApiController::class, 'updateAccount'])->setName('api.accounts.update');
    $app->post('/api/accounts/{id}/link-depot', [ApiController::class, 'linkAccountToDepot'])->setName('api.accounts.linkDepot');
    $app->get('/api/depots-for-linking', [ApiController::class, 'getDepotsForLinking'])->setName('api.depotsForLinking');
    
    // Database Maintenance API (Duplicates)
    $app->get('/api/maintenance/duplicates', [ApiController::class, 'getDuplicates'])->setName('api.maintenance.duplicates');
    $app->post('/api/maintenance/duplicates/remove', [ApiController::class, 'removeDuplicates'])->setName('api.maintenance.removeDuplicates');
    $app->post('/api/maintenance/regenerate-ids', [ApiController::class, 'regenerateTransactionIds'])->setName('api.maintenance.regenerateIds');
    
    // PayPal API Routes
    $app->post('/api/paypal/test', [PayPalController::class, 'testCredentials'])->setName('api.paypal.test');
    $app->post('/api/paypal/{id}/sync', [PayPalController::class, 'sync'])->setName('api.paypal.sync');
    $app->get('/api/paypal/{id}/transactions', [PayPalController::class, 'getTransactions'])->setName('api.paypal.transactions');
    $app->get('/api/paypal/{id}/balance', [PayPalController::class, 'getBalance'])->setName('api.paypal.balance');
    $app->post('/api/paypal/{id}/mqtt-export', [PayPalController::class, 'setMqttExport'])->setName('api.paypal.mqttExport');
};
