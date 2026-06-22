<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\Http\Controllers\MonitoringController;

// Block C — C7 monitoring routes (Волна B).
//
// Контракт C7 (Gate-A п.17 + R1): READ-ONLY мониторинг фона. Фон проекта = планировщик
// (НЕ async-очередь, QUEUE_CONNECTION=sync), поэтому мониторим notification_outbox (C1)
// + здоровье диспетчера; failed_jobs показываем справочно. Только admin
// (web.admin + calculator.role:owner), строго read-only — НИ одного POST/PUT/PATCH/DELETE.
// Миграций C7 нет (читает notification_outbox из C1 и стандартный failed_jobs).
//
// Роуты живут в том же глобальном контексте (фасад Route, префикс api/v1 из
// RouteServiceProvider), отдельным файлом для бесконфликтного merge-train Блока C.

Route::group([
    'prefix' => 'admin',
    'as' => 'admin.',
    'middleware' => ['web.admin'],
], function () {
    // owner-only сводка по outbox (counts/застрявшие/планировщик/failed_jobs справочно).
    Route::get('/monitoring/outbox', [MonitoringController::class, 'outbox'])
        ->middleware('calculator.role:owner')->name('monitoring-outbox');

    // owner-only список проблемных записей outbox (failed + застрявшие). Read-only.
    Route::get('/monitoring/outbox/problems', [MonitoringController::class, 'problems'])
        ->middleware('calculator.role:owner')->name('monitoring-outbox-problems');
});
