<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\V2\Http\Controllers\StatusAdminController;
use Modules\Calculator\V2\Http\Controllers\StatusController;

// mh-full-plan V2 — статусный слой T05 (лестница 12 статусов, CLIENT/grace, тиры).
//
// Deny-by-default: вся группа за feature.flag:mh_v2_statuses (флаг OFF => 403
// FEATURE_DISABLED). Cabinet (Mini App) — telegram.auth, участник видит только СВОЙ
// статус (IDOR: id клиента не принимается, member из auth). Admin (веб-админка) —
// web.admin; read owner,finance; ручной пересчёт owner-only (amendments NTH-1).

// Cabinet (Mini App партнёра).
Route::group([
    'prefix' => 'cabinet/v2/status',
    'as' => 'cabinet.v2.status.',
    'middleware' => ['telegram.auth', 'feature.flag:mh_v2_statuses'],
], function () {
    Route::get('/', [StatusController::class, 'me'])->name('me');
    Route::get('/ranks', [StatusController::class, 'ranks'])->name('ranks');
});

// Admin (веб-админка, НЕ Mini App).
Route::group([
    'prefix' => 'admin/v2/statuses',
    'as' => 'admin.v2.statuses.',
    'middleware' => ['web.admin', 'feature.flag:mh_v2_statuses'],
], function () {
    Route::get('/{memberId}', [StatusAdminController::class, 'show'])
        ->whereNumber('memberId')->middleware('calculator.role:owner,finance')->name('show');
    Route::get('/{memberId}/evaluations', [StatusAdminController::class, 'evaluations'])
        ->whereNumber('memberId')->middleware('calculator.role:owner,finance')->name('evaluations');
    Route::get('/evaluations/{evaluationId}', [StatusAdminController::class, 'evaluation'])
        ->whereUuid('evaluationId')->middleware('calculator.role:owner,finance')->name('evaluation');
    Route::post('/{memberId}/recompute', [StatusAdminController::class, 'recompute'])
        ->whereNumber('memberId')->middleware('calculator.role:owner')->name('recompute');
});
