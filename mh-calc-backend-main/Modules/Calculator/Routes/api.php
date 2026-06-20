<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\Http\Controllers\CalculatorController;
use Modules\Calculator\Http\Controllers\LocalAuthController;
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





