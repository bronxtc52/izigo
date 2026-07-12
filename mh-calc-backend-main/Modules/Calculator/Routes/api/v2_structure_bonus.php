<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\V2\Http\Controllers\StructureBonusController;

// mh-full-plan V2 — структурная (бинарная) премия T06.
//
// Deny-by-default: cabinet за feature.flag:mh_plan_v2_miniapp (telegram.auth),
// admin за feature.flag:mh_plan_v2_admin (web.admin). Read-группы —
// calculator.role:owner,finance (amendments NTH-1). IDOR: cabinet отдаёт только
// начисления члена из auth (id клиента не принимается).

// Cabinet (Mini App партнёра) — свои начисления.
Route::group([
    'prefix' => 'cabinet/v2/structure-bonus',
    'as' => 'cabinet.v2.structure-bonus.',
    'middleware' => ['telegram.auth', 'feature.flag:mh_plan_v2_miniapp'],
], function () {
    Route::get('/', [StructureBonusController::class, 'mine'])->name('mine');
});

// Admin (веб-админка, НЕ Mini App) — read owner,finance.
Route::group([
    'prefix' => 'admin/v2/structure-bonuses',
    'as' => 'admin.v2.structure-bonuses.',
    'middleware' => ['web.admin', 'feature.flag:mh_plan_v2_admin'],
], function () {
    Route::get('/period/{periodId}', [StructureBonusController::class, 'byPeriod'])
        ->whereNumber('periodId')->middleware('calculator.role:owner,finance')->name('by-period');
    Route::get('/period/{periodId}/member/{memberId}', [StructureBonusController::class, 'breakdown'])
        ->whereNumber('periodId')->whereNumber('memberId')
        ->middleware('calculator.role:owner,finance')->name('breakdown');
});
