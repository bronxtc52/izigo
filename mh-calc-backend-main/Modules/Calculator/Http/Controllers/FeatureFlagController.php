<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\AuditLogService;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use RuntimeException;

/**
 * C3 (Block C): рантайм фиче-флаги. Тонкий контроллер — делегирует FeatureFlagService.
 *  - cabinet (telegram.auth): чтение активных флагов (ключ→bool) для Mini App/cabinet.
 *  - admin (web.admin + calculator.role:owner): список с описанием + переключение.
 * RBAC задаётся на маршрутах. Запретные зоны не трогаем.
 *
 * @group FeatureFlags
 */
class FeatureFlagController
{
    public function __construct(
        private readonly FeatureFlagService $service,
        private readonly AuditLogService $audit,
    ) {
    }

    /** Cabinet: только включённые флаги (ключ→true). Deny-by-default — неизвестных нет. */
    public function active(): JsonResponse
    {
        return $this->guarded(fn () => $this->service->enabled());
    }

    /** Admin (owner): все флаги с описанием и статусом. */
    public function index(): JsonResponse
    {
        return $this->guarded(fn () => $this->service->list());
    }

    /** Admin (owner): переключить/задать флаг. Пишется в аудит. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => 'required|string|max:255',
            'enabled' => 'required|boolean',
        ]);

        return $this->guarded(function () use ($request, $data) {
            $this->service->set($data['key'], (bool) $data['enabled'], $this->viewer($request)->id);
            $this->audit->recordSafe(
                $this->viewer($request)->id,
                'feature_flag.set',
                'feature_flag',
                null,
                null,
                ['key' => $data['key'], 'enabled' => (bool) $data['enabled']],
            );

            return $this->service->list();
        });
    }

    /** Текущий участник-наблюдатель (web.admin резолвит в request('member')). */
    private function viewer(Request $request): Member
    {
        return $request->attributes->get('member');
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Не найдено'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
