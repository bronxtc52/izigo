<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Calculator\Services\Monitoring\SchedulerHeartbeat;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
| ⚠️ Роуты бизнес-логики живут в Modules/Calculator/Routes/api.php (префикс api/v1).
| Здесь — только инфраструктурный health-эндпоинт (см. B-5/M1), чтобы не конфликтовать
| с параллельными правками модульного файла.
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
 * M1 + B-5: health-эндпоинт для ACA-проб и server-watchdog. БЕЗ auth, дешёвый.
 * 200 — БД доступна (SELECT 1) И heartbeat планировщика свежий (см. SchedulerHeartbeat).
 * 503 — что-то из этого не так; тело указывает, что именно (в т.ч. «встал планировщик»).
 * Роут отдаётся по /api/health (root routes/api.php грузится с префиксом `api`).
 */
Route::get('/health', function (SchedulerHeartbeat $heartbeat) {
    $checks = [];
    $healthy = true;

    // (a) БД доступна.
    try {
        DB::select('select 1');
        $checks['database'] = 'ok';
    } catch (\Throwable $e) {
        $checks['database'] = 'fail';
        $healthy = false;
    }

    // (b) Планировщик тикал недавно (денежные кроны идут через schedule:work).
    $age = $heartbeat->ageSeconds();
    if ($heartbeat->isFresh()) {
        $checks['scheduler'] = 'ok';
    } else {
        $checks['scheduler'] = $age === null ? 'no-heartbeat' : 'stale';
        $healthy = false;
    }

    return response()->json([
        'status' => $healthy ? 'ok' : 'unhealthy',
        'checks' => $checks,
        'scheduler_heartbeat_age_seconds' => $age,
        'scheduler_heartbeat_threshold_seconds' => SchedulerHeartbeat::FRESH_SECONDS,
        'note' => $healthy ? null : 'Проверьте планировщик (schedule:work в docker/start.sh) и/или БД.',
    ], $healthy ? 200 : 503);
});
