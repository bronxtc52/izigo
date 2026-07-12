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
    Route::get('/accounts', [\Modules\Calculator\V2\Http\Controllers\AccountsV2Controller::class, 'accounts'])
        ->name('accounts');
    Route::get('/accounts/lots', [\Modules\Calculator\V2\Http\Controllers\AccountsV2Controller::class, 'lots'])
        ->name('accounts-lots');
    Route::get('/accounts/history', [\Modules\Calculator\V2\Http\Controllers\AccountsV2Controller::class, 'history'])
        ->name('accounts-history');
    // Оплата со счетов меняет деньги → дополнительно гейтится флагом V2-движка:
    // capture-хук в markPaid дремлет за mh_plan_v2_engine, резерв без него запрещён
    // (иначе захолженные средства никогда не были бы закапчерены).
    Route::post('/orders/{id}/account-payment', [\Modules\Calculator\V2\Http\Controllers\AccountsV2Controller::class, 'accountPayment'])
        ->middleware('feature.flag:mh_plan_v2_engine')
        ->where('id', '[0-9]+')->name('orders-account-payment');
    Route::delete('/orders/{id}/account-payment', [\Modules\Calculator\V2\Http\Controllers\AccountsV2Controller::class, 'cancelAccountPayment'])
        ->middleware('feature.flag:mh_plan_v2_engine')
        ->where('id', '[0-9]+')->name('orders-account-payment-cancel');
    // <<< V2 T02
});

// Admin: обзор счетов/лотов, ручные операции.
Route::group([
    'prefix' => 'admin/v2',
    'as' => 'admin.v2.',
    'middleware' => ['web.admin', 'feature.flag:mh_plan_v2_admin'],
], function () {
    // >>> V2 T02: read-only минимум для T13 (роль в middleware, не в комментах — amendments #1)
    Route::get('/members/{id}/accounts', [\Modules\Calculator\V2\Http\Controllers\AccountsV2Controller::class, 'adminMemberAccounts'])
        ->middleware('calculator.role:owner,finance')
        ->where('id', '[0-9]+')->name('members-accounts');
    Route::get('/members/{id}/lots', [\Modules\Calculator\V2\Http\Controllers\AccountsV2Controller::class, 'adminMemberLots'])
        ->middleware('calculator.role:owner,finance')
        ->where('id', '[0-9]+')->name('members-lots');
    // <<< V2 T02
});
