<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\V2\Http\Controllers\PoolAdminController;

// mh-full-plan V2 — 60%-калибровка выплат T11 (payout pool, DEC-014/029/053).
//
// Deny-by-default: вся группа за feature.flag:mh_v2_pool (флаг OFF => 403 FEATURE_DISABLED).
// Admin (веб-админка, НЕ Mini App) — web.admin; read — owner,finance; recalibrate — owner
// (amendments NTH-1, роль в middleware, не в комментариях). Денег контроллер на счета не
// постит (структурную НС→ОС переводит T04/T02 по закоммиченному factor_bps). recalibrate
// на CLOSED периоде → 422 (закрытый период правит только T12).

Route::group([
    'prefix' => 'admin/v2/pool',
    'as' => 'admin.v2.pool.',
    'middleware' => ['web.admin', 'feature.flag:mh_v2_pool'],
], function () {
    Route::get('/periods', [PoolAdminController::class, 'periods'])
        ->middleware('calculator.role:owner,finance')->name('periods');
    Route::get('/periods/{code}', [PoolAdminController::class, 'period'])
        ->where('code', '\d{4}-\d{2}')->middleware('calculator.role:owner,finance')->name('period');
    Route::get('/periods/{code}/members', [PoolAdminController::class, 'members'])
        ->where('code', '\d{4}-\d{2}')->middleware('calculator.role:owner,finance')->name('members');
    Route::post('/periods/{code}/recalibrate', [PoolAdminController::class, 'recalibrate'])
        ->where('code', '\d{4}-\d{2}')->middleware('calculator.role:owner')->name('recalibrate');
});
