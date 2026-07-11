<?php

use Illuminate\Support\Facades\Route;

// mh-full-plan V2 — счета ОС/НС/БС (плейсхолдер каркаса W0; наполняет T02).
//
// Контракты (amendments): mutation admin-роуты (mark-paid и т.п.) —
// calculator.role:owner; read — calculator.role:owner,finance (nice-to-have #1).
// Cabinet-эндпоинты оплаты со счетов: заказ резолвится через аутентифицированного
// члена, negative-тест на чужой order id обязателен (IDOR, nice-to-have #2).

// Cabinet (партнёр, Mini App): балансы субсчетов, оплата с ОС ≤70%.
Route::group([
    'prefix' => 'cabinet/v2',
    'as' => 'cabinet.v2.',
    'middleware' => ['telegram.auth', 'feature.flag:mh_plan_v2_miniapp'],
], function () {
    // >>> V2 T02
    // <<< V2 T02
});

// Admin: обзор счетов/лотов, ручные операции.
Route::group([
    'prefix' => 'admin/v2',
    'as' => 'admin.v2.',
    'middleware' => ['web.admin', 'feature.flag:mh_plan_v2_admin'],
], function () {
    // >>> V2 T02: read — ->middleware('calculator.role:owner,finance');
    //     mutation — ->middleware('calculator.role:owner')
    // <<< V2 T02
});
