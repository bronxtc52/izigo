<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\V2\Http\Controllers\ReferralRewardController;

// mh-full-plan V2 — реферальная премия T07 (10% L1 / 0-5-8% L2, на ОС сразу после оплаты).
//
// Deny-by-default: вся группа за feature.flag:mh_v2_referral (флаг OFF => 403
// FEATURE_DISABLED). Cabinet (Mini App) — telegram.auth, участник видит только СВОИ
// полученные премии (IDOR: id не принимается, beneficiary из auth). Admin (веб-админка) —
// web.admin; read owner-only (amendments NTH-1; read-группа наград — owner для money-данных).

// Cabinet (Mini App партнёра): мои реферальные премии.
Route::group([
    'prefix' => 'cabinet/v2/referral-rewards',
    'as' => 'cabinet.v2.referral-rewards.',
    'middleware' => ['telegram.auth', 'feature.flag:mh_v2_referral'],
], function () {
    Route::get('/', [ReferralRewardController::class, 'mine'])->name('mine');
});

// Admin (веб-админка, НЕ Mini App): список премий с фильтрами (для T13).
// Read — owner + finance (amendments NTH-1: read-группы owner,finance; приоритет над
// планом §563 owner-only). Мутаций у T07 нет (сторно — T12, калибровка net — T11).
Route::group([
    'prefix' => 'admin/v2/referral-rewards',
    'as' => 'admin.v2.referral-rewards.',
    'middleware' => ['web.admin', 'feature.flag:mh_v2_referral'],
], function () {
    Route::get('/', [ReferralRewardController::class, 'index'])
        ->middleware('calculator.role:owner,finance')->name('index');
});
