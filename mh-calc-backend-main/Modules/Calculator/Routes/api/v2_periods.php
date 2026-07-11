<?php

use Illuminate\Support\Facades\Route;

// mh-full-plan V2 — расчётные периоды (плейсхолдер каркаса W0; наполняет T04).
//
// Контракты (amendments, nice-to-have #1): mutation (ручное закрытие/перезапуск джоба)
// — calculator.role:owner; read (статусы периодов, снапшоты) —
// calculator.role:owner,finance. Изменение закрытых периодов запрещено —
// только корректирующие проводки.

// Admin: статусы периодов v2_calc_periods, календарь закрытий, калибровки.
Route::group([
    'prefix' => 'admin/v2',
    'as' => 'admin.v2.',
    'middleware' => ['web.admin', 'feature.flag:mh_plan_v2_admin'],
], function () {
    // >>> V2 T04: периоды — read owner,finance; ручное закрытие owner-only (идемпотентно)
    Route::get('/periods', [\Modules\Calculator\V2\Http\Controllers\PeriodAdminController::class, 'index'])
        ->middleware('calculator.role:owner,finance')->name('periods');
    Route::get('/periods/{id}', [\Modules\Calculator\V2\Http\Controllers\PeriodAdminController::class, 'show'])
        ->whereNumber('id')->middleware('calculator.role:owner,finance')->name('periods-show');
    Route::post('/periods/{id}/close', [\Modules\Calculator\V2\Http\Controllers\PeriodAdminController::class, 'close'])
        ->whereNumber('id')->middleware('calculator.role:owner')->name('periods-close');
    // <<< V2 T04
});
