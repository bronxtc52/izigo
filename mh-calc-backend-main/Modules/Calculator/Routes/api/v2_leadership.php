<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\V2\Http\Controllers\LeadershipBonusController;

// mh-full-plan V2 — лидерский бонус T08 (глубина до 7, START 10% / BUSINESS 15% /
// ELITE 20-1% по статусной глубине, на ОС; DEC-029/030).
//
// Deny-by-default: вся группа за feature.flag:mh_v2_leadership (флаг OFF => 403
// FEATURE_DISABLED). Cabinet (Mini App) — telegram.auth, участник видит только СВОИ
// начисления (IDOR: id не принимается, receiver из auth). Admin (веб-админка) —
// web.admin; read owner (money-данные + причины исключений/blocking_member).

// Cabinet (Mini App партнёра): мои лидерские начисления.
Route::group([
    'prefix' => 'cabinet/v2/leadership',
    'as' => 'cabinet.v2.leadership.',
    'middleware' => ['telegram.auth', 'feature.flag:mh_v2_leadership'],
], function () {
    Route::get('/', [LeadershipBonusController::class, 'mine'])->name('mine');
});

// Admin (веб-админка, НЕ Mini App): отчёт по периоду (начисления/исключения, для T13).
// Read — owner (money-данные и аудит блокировок).
Route::group([
    'prefix' => 'admin/v2/leadership',
    'as' => 'admin.v2.leadership.',
    'middleware' => ['web.admin', 'feature.flag:mh_v2_leadership'],
], function () {
    Route::get('/', [LeadershipBonusController::class, 'index'])
        ->middleware('calculator.role:owner')->name('index');
});
