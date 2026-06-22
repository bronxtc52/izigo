<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\Http\Controllers\CopartnerAdminController;
use Modules\Calculator\Http\Controllers\CopartnerController;

// Block C — C6 copartners routes (Волна A).
//
// Контракт C6 (Gate-A): несколько записей со-партнёров/наследников БЕЗ валидации суммы
// (п.15); админка read-only (п.16). Партнёр заводит/смотрит/правит/удаляет ТОЛЬКО свои
// записи (cabinet, telegram.auth, scope текущего участника); админ — ТОЛЬКО просмотр
// (web.admin + calculator.role:owner,finance,support). Чисто справочные данные —
// НЕ влияют на деньги/дерево/движок/авторизацию. Миграции — 2026_06_22_0530xx.
//
// Роуты живут в том же глобальном контексте (фасад Route, префикс api/v1 из
// RouteServiceProvider), отдельным файлом для бесконфликтного merge-train Блока C.

// Cabinet (партнёр, Mini App): свои со-партнёры/наследники. CRUD ТОЛЬКО своих записей.
Route::group([
    'prefix' => 'cabinet',
    'as' => 'cabinet.',
    'middleware' => ['telegram.auth'],
], function () {
    Route::get('/copartners', [CopartnerController::class, 'index'])->name('copartners');
    Route::post('/copartners', [CopartnerController::class, 'store'])->name('copartners-store');
    Route::put('/copartners/{id}', [CopartnerController::class, 'update'])
        ->where('id', '[0-9]+')->name('copartners-update');
    Route::delete('/copartners/{id}', [CopartnerController::class, 'destroy'])
        ->where('id', '[0-9]+')->name('copartners-destroy');
});

// Admin (web.admin + owner,finance,support): READ-ONLY просмотр со-партнёров участника.
// Никаких write-роутов в админке (контракт Gate-A п.16).
Route::group([
    'prefix' => 'admin',
    'as' => 'admin.',
    'middleware' => ['web.admin'],
], function () {
    Route::get('/members/{id}/copartners', [CopartnerAdminController::class, 'index'])
        ->middleware('calculator.role:owner,finance,support')
        ->where('id', '[0-9]+')->name('members-copartners');
});
