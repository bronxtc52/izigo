<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\Http\Controllers\FeatureFlagController;

// Block C — C3 feature_flags routes.
//
// Контракт C3 (Gate-A): флаги заранее выключены (deny-by-default), чтение через
// cabinet-auth (telegram.auth), управление owner-only (admin, web.admin +
// calculator.role:owner). Миграции C3 — диапазон 2026_06_22_0520xx.
//
// Роуты живут в том же глобальном контексте (фасад Route, префикс api/v1 из
// RouteServiceProvider), отдельным файлом для бесконфликтного merge-train Блока C.

// Cabinet (партнёр, Mini App): чтение активных флагов (ключ→bool).
Route::group([
    'prefix' => 'cabinet',
    'as' => 'cabinet.',
    'middleware' => ['telegram.auth'],
], function () {
    Route::get('/feature-flags', [FeatureFlagController::class, 'active'])->name('feature-flags');
});

// Admin (owner-only): список флагов с описанием + переключение.
Route::group([
    'prefix' => 'admin',
    'as' => 'admin.',
    'middleware' => ['web.admin'],
], function () {
    Route::get('/feature-flags', [FeatureFlagController::class, 'index'])
        ->middleware('calculator.role:owner')->name('feature-flags');
    Route::post('/feature-flags', [FeatureFlagController::class, 'update'])
        ->middleware('calculator.role:owner')->name('feature-flags-update');
});
