<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\V2\Http\Controllers\RefundAdminController;

// mh-full-plan V2 — возвраты/сторно T12 (reversal всех бонусов, корректировки
// закрытых периодов). Возврат средств покупателю (USDT) — ВНЕ системы; API только
// фиксирует факт возврата и сторнирует внутренние начисления даунлайну.
//
// Deny-by-default: вся группа за feature.flag:mh_v2_refunds (OFF => 403). Только
// admin (веб-админка, web.admin — НЕ Mini App). RBAC (amendments NTH-1, роль в
// middleware): read (список/деталь/очередь корректировок) — owner,finance;
// mutation (create возврата, approve/reject/post корректировки) — owner-only.
Route::group([
    'prefix' => 'admin/v2/refunds',
    'as' => 'admin.v2.refunds.',
    'middleware' => ['web.admin', 'feature.flag:mh_v2_refunds'],
], function () {
    Route::get('/', [RefundAdminController::class, 'index'])
        ->middleware('calculator.role:owner,finance')->name('index');
    Route::post('/', [RefundAdminController::class, 'create'])
        ->middleware('calculator.role:owner')->name('create');
    Route::get('/{id}', [RefundAdminController::class, 'show'])
        ->whereNumber('id')->middleware('calculator.role:owner,finance')->name('show');
});

Route::group([
    'prefix' => 'admin/v2/period-corrections',
    'as' => 'admin.v2.corrections.',
    'middleware' => ['web.admin', 'feature.flag:mh_v2_refunds'],
], function () {
    Route::get('/', [RefundAdminController::class, 'corrections'])
        ->middleware('calculator.role:owner,finance')->name('index');
    Route::post('/{id}/approve', [RefundAdminController::class, 'approveCorrection'])
        ->whereNumber('id')->middleware('calculator.role:owner')->name('approve');
    Route::post('/{id}/reject', [RefundAdminController::class, 'rejectCorrection'])
        ->whereNumber('id')->middleware('calculator.role:owner')->name('reject');
    Route::post('/{id}/post', [RefundAdminController::class, 'postCorrection'])
        ->whereNumber('id')->middleware('calculator.role:owner')->name('post');
});
