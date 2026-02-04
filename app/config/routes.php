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
    
    // Settings Routes
    $app->get('/settings', [\App\Controllers\SettingsController::class, 'index'])->setName('settings');
    $app->post('/settings', [\App\Controllers\SettingsController::class, 'save'])->setName('settings.save');
    
    // API Routes
    $app->post('/api/banks/test', [ApiController::class, 'testConnection'])->setName('api.banks.test');
    $app->get('/api/banks/{id}/accounts', [ApiController::class, 'getAccounts'])->setName('api.banks.accounts');
    $app->post('/api/banks/{id}/tan', [ApiController::class, 'submitTan'])->setName('api.banks.tan');
};
