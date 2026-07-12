<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Calculator\V2\Models\BinaryMatch;
use Modules\Calculator\V2\Models\MemberBranchStat;
use Modules\Calculator\V2\Models\OrderVolumeSnapshot;
use Modules\Calculator\V2\Models\PvLot;
use Modules\Calculator\V2\Services\Volume\BinaryMatchingService;
use Modules\Calculator\V2\Services\Volume\PvLotVolumeService;
use RuntimeException;

/**
 * T03: admin-обзор volume-слоя V2 (веб-админка, НЕ Mini App) + ручной прогон
 * матчинга (до появления джобов T04). RBAC на роутах (v2_volumes.php):
 * read — calculator.role:owner,finance; POST run — только owner
 * (amendments nice-to-have #1); вся группа за feature.flag:mh_v2_volumes.
 * Денег здесь нет — только объёмы (PV/BV) и provenance.
 */
class VolumeAdminController
{
    private const PAGE_LIMIT = 100;

    public function __construct(
        private readonly BinaryMatchingService $matching,
        private readonly PvLotVolumeService $volumes,
    ) {
    }

    /** Лоты: фильтры member_id/side/state, новые сверху, до 100 строк. */
    public function pvLots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_id' => 'sometimes|integer',
            'side' => 'sometimes|in:left,right',
            'state' => 'sometimes|in:free,grace_held,exhausted,reversed',
        ]);

        return $this->guarded(function () use ($data) {
            return PvLot::query()
                ->when(isset($data['member_id']), fn ($q) => $q->where('owner_member_id', $data['member_id']))
                ->when(isset($data['side']), fn ($q) => $q->where('side', $data['side']))
                ->when(isset($data['state']), fn ($q) => $q->where('state', $data['state']))
                ->orderByDesc('id')
                ->limit(self::PAGE_LIMIT)
                ->get();
        });
    }

    /** Матчи: фильтры member_id/period_key/status, новые сверху. */
    public function binaryMatches(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_id' => 'sometimes|integer',
            'period_key' => 'sometimes|string|max:20',
            'status' => 'sometimes|in:provisional,final,reversed',
        ]);

        return $this->guarded(function () use ($data) {
            return BinaryMatch::query()
                ->when(isset($data['member_id']), fn ($q) => $q->where('member_id', $data['member_id']))
                ->when(isset($data['period_key']), fn ($q) => $q->where('period_key', $data['period_key']))
                ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
                ->orderByDesc('id')
                ->limit(self::PAGE_LIMIT)
                ->get();
        });
    }

    /** Роли веток участника (проекция v2_member_branch_stats). */
    public function branchStats(int $memberId): JsonResponse
    {
        return $this->guarded(
            fn () => MemberBranchStat::query()->findOrFail($memberId)
        );
    }

    /** BV/PV-снапшоты заказа. */
    public function orderVolumeSnapshots(Request $request): JsonResponse
    {
        $data = $request->validate(['order_id' => 'required|integer']);

        return $this->guarded(
            fn () => OrderVolumeSnapshot::query()->where('order_id', $data['order_id'])->get()
        );
    }

    /** Ручной прогон матчинга (owner-only; до T04-джобов — тесты/отладка). */
    public function runMatching(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_id' => 'required|integer|exists:members,id',
            'period_key' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])-(H1|H2)$/'],
            'run_uuid' => 'sometimes|string|max:64',
        ]);

        return $this->guarded(function () use ($data) {
            // Ревью W1 MF-7 (amendments #5): контроллер — внешний оркестратор ручного
            // матчинга, он и берёт advisory-lock активаций (сериализация с инжестом
            // лотов оплаты и V1-пересчётом); сервис внутри — assertLockHeld().
            $match = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
                app(\Modules\Calculator\Services\ActivationService::class)->acquireActivationLock();

                return $this->matching->runMatching(
                    (int) $data['member_id'],
                    PvLotVolumeService::cutoffForPeriod($data['period_key']),
                    $data['period_key'],
                    $data['run_uuid'] ?? ('period:' . $data['period_key']),
                );
            });

            return $match->load('allocations');
        });
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Не найдено'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
