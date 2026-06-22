<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\Http\Controllers\MemberExportController;

// Block C — C5 exports routes.
//
// Контракт C5 (Gate-A): полный PII-режим — маска по умолчанию + reveal owner-only
// + аудит; экспорт JSON + CSV. PII = telegram_username / payout_details / KYC.
// Только admin (web.admin); reveal — calculator.role:owner; сводки/экспорт —
// owner,finance,support (для не-owner экспорт принудительно маскирован в контроллере).
// Миграций C5 нет (читает существующие таблицы; аудит через admin_audit_log).
//
// Префикс api/v1 добавляет RouteServiceProvider. Группа admin под web.admin.
Route::group([
    'prefix' => 'admin',
    'as' => 'admin.',
    'middleware' => ['web.admin'],
], function () {
    // Сводка участника с PII в маске (owner,finance,support).
    Route::get('/members/{id}/pii', [MemberExportController::class, 'summary'])
        ->middleware('calculator.role:owner,finance,support')
        ->where('id', '[0-9]+')->name('member-pii-summary');

    // Reveal сырых PII — ТОЛЬКО owner (deny-by-default для остальных).
    Route::post('/members/{id}/pii/reveal', [MemberExportController::class, 'reveal'])
        ->middleware('calculator.role:owner')
        ->where('id', '[0-9]+')->name('member-pii-reveal');

    // Экспорт csv|json (owner,finance,support). masked=false (полный) — только owner (в коде).
    Route::get('/members/{id}/export', [MemberExportController::class, 'export'])
        ->middleware('calculator.role:owner,finance,support')
        ->where('id', '[0-9]+')->name('member-export');
});
