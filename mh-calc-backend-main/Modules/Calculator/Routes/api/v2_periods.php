<?php

use Illuminate\Support\Facades\Route;

// mh-full-plan V2 — расчётные периоды (плейсхолдер каркаса W0; наполняет T04).
//
// Контракты (amendments, nice-to-have #1): mutation (ручное закрытие/перезапуск джоба)
// — calculator.role:owner; read (статусы периодов, снапшоты) —
// calculator.role:owner,finance. Изменение закрытых периодов запрещено —
// только корректирующие проводки.

// Admin: статусы периодов v2_calc_periods, календарь закрытий, калибровки.
Route::group([
    'prefix' => 'admin/v2',
    'as' => 'admin.v2.',
    'middleware' => ['web.admin', 'feature.flag:mh_plan_v2_admin'],
], function () {
    // >>> V2 T04: read — ->middleware('calculator.role:owner,finance');
    //     mutation — ->middleware('calculator.role:owner')
    // <<< V2 T04
});
