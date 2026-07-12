<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\V2\Http\Controllers\AwardsController;

// mh-full-plan V2 — квалификационные награды T10 (единоразовые суммы USD за статусы
// Manager..VP на Бонусный счёт, ручная выплата).
//
// Deny-by-default: вся группа за feature.flag:mh_v2_awards (OFF => 403
// FEATURE_DISABLED). Cabinet (Mini App) — telegram.auth, участник видит только СВОИ
// награды (IDOR: id не принимается, member из auth). Admin (веб-админка) — web.admin;
// read (очередь) — owner,finance; mutation (mark-paid/hold/release/forfeit) —
// owner-only (amendments NTH-1, роль в middleware, не в комментах).

// Cabinet (Mini App партнёра).
Route::group([
    'prefix' => 'cabinet/v2/awards',
    'as' => 'cabinet.v2.awards.',
    'middleware' => ['telegram.auth', 'feature.flag:mh_v2_awards'],
], function () {
    Route::get('/', [AwardsController::class, 'me'])->name('me');
});

// Admin (веб-админка, НЕ Mini App).
Route::group([
    'prefix' => 'admin/v2/awards',
    'as' => 'admin.v2.awards.',
    'middleware' => ['web.admin', 'feature.flag:mh_v2_awards'],
], function () {
    Route::get('/', [AwardsController::class, 'queue'])
        ->middleware('calculator.role:owner,finance')->name('queue');
    Route::post('/{id}/mark-paid', [AwardsController::class, 'markPaid'])
        ->whereNumber('id')->middleware('calculator.role:owner')->name('mark-paid');
    Route::post('/{id}/hold', [AwardsController::class, 'hold'])
        ->whereNumber('id')->middleware('calculator.role:owner')->name('hold');
    Route::post('/{id}/release', [AwardsController::class, 'release'])
        ->whereNumber('id')->middleware('calculator.role:owner')->name('release');
    Route::post('/{id}/forfeit', [AwardsController::class, 'forfeit'])
        ->whereNumber('id')->middleware('calculator.role:owner')->name('forfeit');
});
