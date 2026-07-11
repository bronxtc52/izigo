<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\V2\Http\Controllers\VolumeAdminController;

// mh-full-plan V2 — volume-слой T03 (PV-лоты бинара, матчинг, branch-stats).
//
// Deny-by-default: вся группа за feature.flag:mh_v2_volumes (флаг OFF => 403
// FEATURE_DISABLED) + web.admin. RBAC (amendments nice-to-have #1): read —
// calculator.role:owner,finance; mutation (ручной прогон матчинга) — owner-only.
// Cabinet/Mini App-роутов у T03 нет (фронты — T13/T14).

Route::group([
    'prefix' => 'admin/v2/volumes',
    'as' => 'admin.v2.volumes.',
    'middleware' => ['web.admin', 'feature.flag:mh_v2_volumes'],
], function () {
    Route::get('/pv-lots', [VolumeAdminController::class, 'pvLots'])
        ->middleware('calculator.role:owner,finance')->name('pv-lots');
    Route::get('/binary-matches', [VolumeAdminController::class, 'binaryMatches'])
        ->middleware('calculator.role:owner,finance')->name('binary-matches');
    Route::get('/branch-stats/{memberId}', [VolumeAdminController::class, 'branchStats'])
        ->middleware('calculator.role:owner,finance')->where('memberId', '[0-9]+')->name('branch-stats');
    Route::get('/order-volume-snapshots', [VolumeAdminController::class, 'orderVolumeSnapshots'])
        ->middleware('calculator.role:owner,finance')->name('order-volume-snapshots');

    Route::post('/binary-matches/run', [VolumeAdminController::class, 'runMatching'])
        ->middleware('calculator.role:owner')->name('binary-matches-run');
});
