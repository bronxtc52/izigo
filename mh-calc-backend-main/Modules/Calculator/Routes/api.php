<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\Http\Controllers\AdminController;
use Modules\Calculator\Http\Controllers\CabinetController;
use Modules\Calculator\Http\Controllers\CalculatorController;
use Modules\Calculator\Http\Controllers\LocalAuthController;
use Modules\Calculator\Http\Controllers\MiniAppController;
use Modules\Calculator\Http\Controllers\PackageController;
use Modules\Calculator\Http\Controllers\RankController;
use Modules\Calculator\Http\Middleware\StructureEditTokenMiddleware;

// Локальная авторизация (email+пароль) — единственный способ входа
Route::group([
    'prefix' => 'auth',
    'as' => 'calculator.auth.',
], function () {
    Route::post('/register', [LocalAuthController::class, 'register'])->name('register');
    Route::post('/login', [LocalAuthController::class, 'login'])->name('login');
});

Route::group([
    'prefix' => 'calculator',
    'as' => 'calculator.'
], function () {

    // Структура - редактирование
    Route::group([
        'prefix' => 'structure',
        'as' => 'structure.',
        'middleware' => ['calculator.validate.token']
    ], function () {
        Route::get('/last', [CalculatorController::class, 'structureGetLast'])->name('structure-get-last');
        Route::get('/all', [CalculatorController::class, 'structureGetAll'])->name('structure-get-all');
        Route::post('/', [CalculatorController::class, 'structureCreate'])->name('create');

        Route::group([
            'prefix' => 'node',
            'as' => 'node.',
        ], function () {
            Route::post('/set-structure-package', [CalculatorController::class, 'setStructurePackage'])->name('set-structure-package');
            Route::post('/create', [CalculatorController::class, 'addNode'])->name('create');
            Route::put('/update/{node_id}', [CalculatorController::class, 'updateNode'])->name('update')
                ->where(['node_id' => '[0-9]+']);
            Route::delete('/delete/{node_id}', [CalculatorController::class, 'deleteNode'])->name('delete')
                ->where(['node_id' => '[0-9]+']);
        });

        Route::delete('/{structure}', [CalculatorController::class, 'structureClear'])->name('clear')
            ->where(['structure' => '[A-Za-z0-9]+'])
            ->middleware(StructureEditTokenMiddleware::class . ':structure');

        Route::get('/{structure_token}/details/{node_id}', [CalculatorController::class, 'getNodeDetails'])
            ->name('node-details')
            ->where(['structure_token' => '[A-Za-z0-9]+', 'node_id' => '[0-9]+']);

        Route::get('/{structure_token}', [CalculatorController::class, 'getStructure'])->name('index')
            ->where(['structure_token' => '[A-Za-z0-9]+']);
    });

    // Структура на просмотр
    /*Route::group([
        'prefix' => 'structure',
        'as' => 'structure.'
    ], function () {
        Route::get('/{structure_token}/details/{node_id}', [CalculatorController::class, 'getNodeDetails'])
            ->name('node-details')
            ->where(['structure_token' => '[A-Za-z0-9]+', 'node_id' => '[0-9]+']);

        Route::get('/{structure_token}', [CalculatorController::class, 'getStructure'])->name('index')
            ->where(['structure_token' => '[A-Za-z0-9]+']);
    });*/

// Пакеты
    Route::get('/packages', [PackageController::class, 'index'])->name('packages');

// Ранги
    Route::get('/ranks', [RankController::class, 'index'])->name('ranks');

});

// Кабинет партнёра — требует валидный токен; участник резолвится из токена.
Route::group([
    'prefix' => 'cabinet',
    'as' => 'cabinet.',
    'middleware' => ['calculator.validate.token'],
], function () {
    Route::get('/me', [CabinetController::class, 'me'])->name('me');
    Route::get('/dashboard', [CabinetController::class, 'dashboard'])->name('dashboard');
    Route::get('/rank-progress', [CabinetController::class, 'rankProgress'])->name('rank-progress');
    Route::get('/team-tree', [CabinetController::class, 'teamTree'])->name('team-tree');
    Route::post('/activate-package', [CabinetController::class, 'activate'])->name('activate-package');
});

// Telegram Mini App — авторизация по initData (заголовок X-Telegram-Init-Data),
// без CalculatorAuthToken. Участник резолвится/создаётся по telegram_id.
Route::group([
    'prefix' => 'miniapp',
    'as' => 'miniapp.',
], function () {
    Route::get('/me', [MiniAppController::class, 'me'])->name('me');
    Route::get('/dashboard', [MiniAppController::class, 'dashboard'])->name('dashboard');
    Route::get('/rank-progress', [MiniAppController::class, 'rankProgress'])->name('rank-progress');
    Route::get('/team-tree', [MiniAppController::class, 'teamTree'])->name('team-tree');
    Route::post('/activate-package', [MiniAppController::class, 'activate'])->name('activate-package');
});

// Админ-портал — токен + RBAC-гейты. owner проходит всегда (в RoleMiddleware).
Route::group([
    'prefix' => 'admin',
    'as' => 'admin.',
    'middleware' => ['calculator.validate.token'],
], function () {
    Route::get('/members', [AdminController::class, 'members'])
        ->middleware('calculator.role:owner,finance,support,leader')->name('members');
    Route::get('/members/{id}', [AdminController::class, 'member'])
        ->middleware('calculator.role:owner,finance,support,leader')->where('id', '[0-9]+')->name('member');
    Route::post('/members/{id}/role', [AdminController::class, 'assignRole'])
        ->middleware('calculator.role:owner')->where('id', '[0-9]+')->name('assign-role');
    Route::delete('/members/{id}/role', [AdminController::class, 'revokeRole'])
        ->middleware('calculator.role:owner')->where('id', '[0-9]+')->name('revoke-role');
    Route::get('/plan-settings', [AdminController::class, 'planSettings'])
        ->middleware('calculator.role:owner,finance,support')->name('plan-settings');
    Route::put('/plan-settings', [AdminController::class, 'updatePlanSettings'])
        ->middleware('calculator.role:owner')->name('update-plan-settings');
});





