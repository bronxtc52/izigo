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

// Приложение headless (Telegram Mini App + API). Web-роутов нет.

/*
 * M1: сверх-дешёвый liveness-эндпоинт /up — только «процесс жив», без БД и без
 * heartbeat. Годится для ACA liveness-пробы (не убивать контейнер из-за вставшего
 * планировщика — это забота readiness/watchdog через /api/health). БЕЗ auth.
 */
Route::get('/up', fn () => response()->json(['status' => 'ok'], 200));
