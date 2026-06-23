<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Services\Monitoring\MonitoringService;

/**
 * C7 (Block C): READ-ONLY мониторинг фонового конвейера уведомлений в веб-админке
 * (web.admin + calculator.role:owner). Только owner (Gate-A п.17, R1).
 *
 * Здесь НЕТ write-методов: контроллер только читает `notification_outbox` (C1) и
 * справочный `failed_jobs`. Фон проекта = планировщик (НЕ async-очередь), поэтому
 * мониторим outbox + здоровье диспетчера, failed_jobs — справочно.
 *
 * @group Monitoring
 */
class MonitoringController
{
    public function __construct(private readonly MonitoringService $monitoring)
    {
    }

    /** Сводка по outbox: counts по статусам + застрявшие + здоровье планировщика. */
    public function outbox(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->monitoring->outboxSummary(),
        ]);
    }

    /** Последние проблемные записи outbox (failed + застрявшие) — только чтение. */
    public function problems(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);

        return response()->json([
            'status' => 'success',
            'data' => $this->monitoring->problemRecords($limit),
        ]);
    }
}
