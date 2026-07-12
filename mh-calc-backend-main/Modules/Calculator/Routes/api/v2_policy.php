<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\V2\Http\Controllers\PolicyVersionAdminController;

// mh-full-plan V2 — политика (плейсхолдер каркаса W0; наполняют T01/T13).
//
// Контракты (amendments, nice-to-have #1): mutation-роуты (activate policy, конфиг)
// — calculator.role:owner; read-роуты — calculator.role:owner,finance. Роль в route
// middleware, не в комментариях; negative-тесты обязательны. Роуты гейтить
// feature.flag:mh_plan_v2_admin (deny-by-default). Cabinet-роутов политики нет
// (Mini App-представление — T14, гейт mh_plan_v2_miniapp).

// Admin: управление версиями политики (v2_policy_versions, статусы draft|active|retired).
Route::group([
    'prefix' => 'admin/v2',
    'as' => 'admin.v2.',
    'middleware' => ['web.admin', 'feature.flag:mh_plan_v2_admin'],
], function () {
    // >>> V2 T01
    // Read (owner + finance): список/просмотр версий, отладочный резолв по дате.
    Route::get('/policy-versions', [PolicyVersionAdminController::class, 'index'])
        ->middleware('calculator.role:owner,finance')->name('policy-versions.index');
    Route::get('/policy-versions/resolve', [PolicyVersionAdminController::class, 'resolve'])
        ->middleware('calculator.role:owner,finance')->name('policy-versions.resolve');
    Route::get('/policy-versions/{id}', [PolicyVersionAdminController::class, 'show'])
        ->whereNumber('id')->middleware('calculator.role:owner,finance')->name('policy-versions.show');

    // Mutation (owner-only): draft-CRUD, one-step активация, retire.
    Route::post('/policy-versions', [PolicyVersionAdminController::class, 'storeDraft'])
        ->middleware('calculator.role:owner')->name('policy-versions.store');
    Route::put('/policy-versions/{id}', [PolicyVersionAdminController::class, 'updateDraft'])
        ->whereNumber('id')->middleware('calculator.role:owner')->name('policy-versions.update');
    Route::post('/policy-versions/{id}/activate', [PolicyVersionAdminController::class, 'activate'])
        ->whereNumber('id')->middleware('calculator.role:owner')->name('policy-versions.activate');
    Route::post('/policy-versions/{id}/retire', [PolicyVersionAdminController::class, 'retire'])
        ->whereNumber('id')->middleware('calculator.role:owner')->name('policy-versions.retire');
    // <<< V2 T01

    // >>> V2 T13
    // <<< V2 T13
});
