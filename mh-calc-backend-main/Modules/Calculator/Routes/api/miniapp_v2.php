<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\V2\Http\Controllers\CabinetPlanController;

// mh-full-plan V2 — T14: единый read-слой таба «Мой план» Mini App (счета ОС/НС/БС,
// прогресс 12 статусов, тир, награды). Один флаг UI mh_plan_v2_miniapp гейтит ВСЕ
// эндпоинты (не движковый cutover-флаг T15). telegram.auth — участник видит только
// СВОИ данные (member из auth, id из запроса не принимается — IDOR структурно).
// Read-only: ни одной записи в ledger. Деньги — integer USD-центы + decimal-строка.

Route::group([
    'prefix' => 'cabinet/v2/plan',
    'as' => 'cabinet.v2.plan.',
    'middleware' => ['telegram.auth', 'feature.flag:mh_plan_v2_miniapp'],
], function () {
    // >>> V2 T14 miniapp plan
    Route::get('/overview', [CabinetPlanController::class, 'overview'])->name('overview');
    Route::get('/rank-progress', [CabinetPlanController::class, 'rankProgress'])->name('rank-progress');
    Route::get('/accounts', [CabinetPlanController::class, 'accounts'])->name('accounts');
    Route::get('/accounts/{account}/lots', [CabinetPlanController::class, 'lots'])
        ->where('account', '[a-z]+')->name('accounts-lots');
    Route::get('/accounts/{account}/history', [CabinetPlanController::class, 'history'])
        ->where('account', '[a-z]+')->name('accounts-history');
    Route::get('/awards', [CabinetPlanController::class, 'awards'])->name('awards');
    // <<< V2 T14 miniapp plan
});
