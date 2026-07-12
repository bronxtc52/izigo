<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\V2\Http\Controllers\GlobalBonusAdminController;

// mh-full-plan V2 — глобальный бонус T09 (месячные пулы Director..VP, квартальная выплата).
//
// Deny-by-default: вся группа за feature.flag:mh_v2_global_bonus (флаг OFF => 403
// FEATURE_DISABLED). Admin (веб-админка, НЕ Mini App) — web.admin; read owner,finance;
// ручной пересчёт draft-месяца owner-only (amendments NTH-1). Денег контроллер не постит
// (выплата — квартальный job T04). Выплаты глобального — только чтение отчётов.

Route::group([
    'prefix' => 'admin/v2/global-bonus',
    'as' => 'admin.v2.global-bonus.',
    'middleware' => ['web.admin', 'feature.flag:mh_v2_global_bonus'],
], function () {
    Route::get('/months', [GlobalBonusAdminController::class, 'months'])
        ->middleware('calculator.role:owner,finance')->name('months');
    Route::get('/months/{code}', [GlobalBonusAdminController::class, 'month'])
        ->where('code', '\d{4}-\d{2}')->middleware('calculator.role:owner,finance')->name('month');
    Route::get('/quarters/{code}', [GlobalBonusAdminController::class, 'quarter'])
        ->where('code', '\d{4}-Q[1-4]')->middleware('calculator.role:owner,finance')->name('quarter');
    Route::post('/months/{code}/recompute', [GlobalBonusAdminController::class, 'recompute'])
        ->where('code', '\d{4}-\d{2}')->middleware('calculator.role:owner')->name('recompute');
});
