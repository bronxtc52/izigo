<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\Http\Controllers\TranslationController;

// Block C — C4 i18n routes (редактируемые переводы, DB-оверрайды поверх статики).
//
// Контракт C4 (Gate-A): покрыть все фронтовые ключи; бэк-locales (Modules/ConfigIziGo) вне
// скоупа — НЕ трогаем. Оверрайды редактирует ТОЛЬКО owner (web.admin + calculator.role:owner).
// Чтение оверрайдов — публичное: переводы не секретны, а логин-страница админки и Mini App
// нуждаются в строках ДО auth; фронт graceful-фолбэк на статику при недоступности эндпоинта.
// Миграции C4 — диапазон 2026_06_22_0540xx (translation_overrides).
//
// Роуты живут в том же глобальном контексте (фасад Route, префикс api/v1 из RouteServiceProvider),
// отдельным файлом для бесконфликтного merge-train Блока C.

// Public: карта оверрайдов для фронт-мёржа (?locale=ru → одна локаль; без — все).
// Сознательно БЕЗ feature.flag:c4_i18n_admin (B5): флаг выключает админ-УПРАВЛЕНИЕ переводами,
// а не runtime-serving — иначе выключенный c4 сломал бы загрузку строк всем пользователям.
Route::get('/i18n/overrides', [TranslationController::class, 'overrides'])->name('i18n.overrides');

// Admin (owner-only): список оверрайдов + upsert + delete. feature.flag:c4_i18n_admin (B5).
Route::group([
    'prefix' => 'admin',
    'as' => 'admin.',
    'middleware' => ['web.admin', 'feature.flag:c4_i18n_admin'],
], function () {
    Route::get('/i18n/overrides', [TranslationController::class, 'index'])
        ->middleware('calculator.role:owner')->name('i18n-overrides');
    Route::post('/i18n/overrides', [TranslationController::class, 'upsert'])
        ->middleware('calculator.role:owner')->name('i18n-overrides-upsert');
    Route::delete('/i18n/overrides', [TranslationController::class, 'delete'])
        ->middleware('calculator.role:owner')->name('i18n-overrides-delete');
});
