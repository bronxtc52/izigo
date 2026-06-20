<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::group([
    'prefix' => 'dev/test',
    'as' => 'dev.test'
], function () {
    //только для тестовых
    if (config('app.test_route_available')) {
        //тестовые скрипты конкретного разработчика
        // dev/test/dev1
        Route::get('/dev1', [\Modules\ConfigIziGo\Http\Controllers\TestController::class, 'dev1'])->name('dev1');
    }
});
