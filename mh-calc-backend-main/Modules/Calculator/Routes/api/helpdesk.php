<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\Http\Controllers\TicketAdminController;
use Modules\Calculator\Http\Controllers\TicketController;

// Block C — C2 helpdesk routes (Волна B).
//
// Контракт C2 (Gate-A): тикеты + сообщения, polling 5–8с, без priority/вложений.
// Партнёр создаёт/читает свои тикеты (cabinet, telegram.auth); операторы отвечают
// (admin, web.admin + calculator.role:owner,support). Пуши — через C1 (best-effort).
// Миграции C2 — диапазон 2026_06_22_0550xx (tickets, ticket_messages).

// Cabinet (партнёр, Mini App): свои тикеты + чат. Скоуп строго по своему member.
Route::group([
    'prefix' => 'cabinet',
    'as' => 'cabinet.',
    'middleware' => ['telegram.auth', 'feature.flag:c2_helpdesk'],
], function () {
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets-create');
    Route::get('/tickets/{id}', [TicketController::class, 'show'])
        ->where('id', '[0-9]+')->name('ticket');
    Route::post('/tickets/{id}/messages', [TicketController::class, 'postMessage'])
        ->where('id', '[0-9]+')->name('ticket-message');
    // Polling треда: новые сообщения после ?since=<lastId>.
    Route::get('/tickets/{id}/poll', [TicketController::class, 'pollMessages'])
        ->where('id', '[0-9]+')->name('ticket-poll');
});

// Admin (web.admin + owner,support): очередь тикетов + чат оператора.
Route::group([
    'prefix' => 'admin',
    'as' => 'admin.',
    'middleware' => ['web.admin', 'feature.flag:c2_helpdesk'],
], function () {
    Route::get('/tickets', [TicketAdminController::class, 'index'])
        ->middleware('calculator.role:owner,support')->name('tickets');
    Route::get('/tickets/{id}', [TicketAdminController::class, 'show'])
        ->middleware('calculator.role:owner,support')->where('id', '[0-9]+')->name('ticket');
    Route::post('/tickets/{id}/reply', [TicketAdminController::class, 'reply'])
        ->middleware('calculator.role:owner,support')->where('id', '[0-9]+')->name('ticket-reply');
    Route::post('/tickets/{id}/status', [TicketAdminController::class, 'setStatus'])
        ->middleware('calculator.role:owner,support')->where('id', '[0-9]+')->name('ticket-status');
    Route::post('/tickets/{id}/assign', [TicketAdminController::class, 'assign'])
        ->middleware('calculator.role:owner,support')->where('id', '[0-9]+')->name('ticket-assign');
});
