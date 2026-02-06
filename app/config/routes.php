<?php
declare(strict_types=1);

use Slim\App;
use App\Controllers\HomeController;
use App\Controllers\BankController;
use App\Controllers\ApiController;

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
    $app->get('/api/accounts/{id}/transactions', [ApiController::class, 'getTransactions'])->setName('api.accounts.transactions');
    $app->post('/api/accounts/{id}/sync', [ApiController::class, 'syncAccount'])->setName('api.accounts.sync');
    $app->post('/api/accounts/{id}/depot', [ApiController::class, 'syncDepotHoldings'])->setName('api.accounts.depot');
    $app->get('/api/accounts/{id}/holdings', [ApiController::class, 'getDepotHoldings'])->setName('api.accounts.holdings');
    
    // Auto-sync API
    $app->post('/api/auto-sync/run', [ApiController::class, 'runAutoSync'])->setName('api.autosync.run');
    $app->get('/api/auto-sync/status', [ApiController::class, 'getAutoSyncStatus'])->setName('api.autosync.status');
    
    // MQTT API
    $app->post('/api/mqtt/test', [ApiController::class, 'testMqtt'])->setName('api.mqtt.test');
    $app->post('/api/mqtt/publish', [ApiController::class, 'publishMqtt'])->setName('api.mqtt.publish');
    $app->post('/api/accounts/{id}/mqtt-export', [ApiController::class, 'setAccountMqttExport'])->setName('api.accounts.mqttExport');
};
