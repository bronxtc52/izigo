<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\Http\Controllers\BroadcastAdminController;
use Modules\Calculator\Http\Controllers\NotificationController;

// Block C — C1 notifications routes (Волна A).
//
// Контракт C1 (Gate-A): NotificationService::enqueueToMember / enqueueForMembers,
// доставка inbox + Telegram, событие MVP = статус выплаты, рассылки owner+support.
// Миграции C1 — диапазон 2026_06_22_0510xx (см. docs/block-c-migration-ledger.md).
//
// Роуты живут в том же глобальном контексте (фасад Route, префикс api/v1 из
// RouteServiceProvider), отдельным файлом для бесконфликтного merge-train Блока C.

// Cabinet (партнёр, Mini App): свой inbox. Видит/трогает ТОЛЬКО свои уведомления.
Route::group([
    'prefix' => 'cabinet',
    'as' => 'cabinet.',
    'middleware' => ['telegram.auth'],
], function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications-unread');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
        ->where('id', '[0-9]+')->name('notifications-read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications-read-all');
});

// Admin (web.admin + owner,support): рассылки — превью охвата (dry-run) + отправка.
Route::group([
    'prefix' => 'admin',
    'as' => 'admin.',
    'middleware' => ['web.admin'],
], function () {
    Route::post('/broadcasts/preview', [BroadcastAdminController::class, 'preview'])
        ->middleware('calculator.role:owner,support')->name('broadcasts-preview');
    Route::post('/broadcasts', [BroadcastAdminController::class, 'send'])
        ->middleware('calculator.role:owner,support')->name('broadcasts-send');
});
