<?php

use Illuminate\Support\Facades\Route;

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
    // >>> V2 T01: read — ->middleware('calculator.role:owner,finance');
    //     mutation (activate, draft-редактирование) — ->middleware('calculator.role:owner')
    // <<< V2 T01

    // >>> V2 T13
    // <<< V2 T13
});
