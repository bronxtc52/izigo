<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2GlobalBonus;
use Modules\Calculator\V2\Models\GlobalBonusAllocation;
use Modules\Calculator\V2\Models\GlobalBonusMonth;
use Modules\Calculator\V2\Models\GlobalBonusPool;
use Modules\Calculator\V2\Models\OrderReturn;
use Modules\Calculator\V2\Models\ReversalAction;
use Modules\Calculator\V2\Services\GlobalBonus\GlobalBonusMonthlyService;
use Modules\Calculator\V2\Services\Refunds\BonusReversalService;
use Tests\TestCase;

/**
 * MF-W5-4 [ДЕНЬГИ/ПРОЦЕСС]: сторно глобального бонуса при возврате (решение владельца,
 * dec-triage §«глобальный»). ОТКРЫТЫЙ (draft) месяц — возврат уменьшает eligible global BV
 * окна и пересчитывает доли; ФИНАЛИЗИРОВАННЫЙ месяц / выплаченный квартал — авто-сторно НЕ
 * проводится, фиксируется owner-manual note. Ранее путь глобального в reversal отсутствовал.
 */
class GlobalBonusReturnReversalTest extends TestCase
{
    use RefreshDatabase;
    use SeedsV2GlobalBonus;

    private const MONTH = '2026-03';

    private function paidAt(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');
    }

    private function service(): GlobalBonusMonthlyService
    {
        return app(GlobalBonusMonthlyService::class);
    }

    private function orderIdOf(int $memberId): int
    {
        return (int) DB::table('v2_order_volume_snapshots')->where('member_id', $memberId)->value('order_id');
    }

    private function makeReturn(int $orderId, int $memberId, int $returnedBvCents, string $key): OrderReturn
    {
        return OrderReturn::query()->create([
            'order_id' => $orderId,
            'member_id' => $memberId,
            'kind' => OrderReturn::KIND_PARTIAL,
            'status' => OrderReturn::STATUS_REVERSING,
            'reason' => 'возврат для теста глобального',
            'returned_bv_cents' => $returnedBvCents,
            'returned_pv' => '0.000000',
            'policy_version_id' => 1,
            'idempotency_key' => $key,
        ]);
    }

    public function testDraftMonthEligibleBvReducedByReturn(): void
    {
        $this->activateGlobalBonusPolicy();
        $period = $this->ensurePeriod(self::MONTH);

        $buyer = $this->makeMember();
        $this->seedSnapshot($buyer, '0.000000', 500_000_000, $this->paidAt());

        $month = $this->service()->allocateForMonth($period);
        $this->assertSame(500_000_000, (int) $month->global_bv_cents);
        $this->assertSame(5_000_000, (int) $month->pools()->where('pool_rank', GlobalBonusPool::RANK_DIRECTOR)->value('pool_amount_cents'));

        // Возврат 100 USD млн BV. Пересчёт draft-месяца обязан вычесть его из базы.
        $this->makeReturn($this->orderIdOf($buyer), $buyer, 100_000_000, 'gret-draft-1');

        $recomputed = $this->service()->allocateForMonth($period);
        $this->assertSame(400_000_000, (int) $recomputed->global_bv_cents, 'MF-W5-4: eligible global BV уменьшен на returned_bv');
        // Пул пропорционально уменьшился: 1% от 400M = 4M.
        $this->assertSame(4_000_000, (int) $recomputed->pools()->where('pool_rank', GlobalBonusPool::RANK_DIRECTOR)->value('pool_amount_cents'));
    }

    public function testReverseGlobalRecomputesDraftMonthAndRecordsNote(): void
    {
        $this->activateGlobalBonusPolicy();
        $period = $this->ensurePeriod(self::MONTH);

        $buyer = $this->makeMember();
        $this->seedSnapshot($buyer, '0.000000', 500_000_000, $this->paidAt());
        $this->service()->allocateForMonth($period);

        $return = $this->makeReturn($this->orderIdOf($buyer), $buyer, 100_000_000, 'gret-draft-2');
        $needsManual = app(BonusReversalService::class)->reverseGlobalForReturn($return);

        $this->assertFalse($needsManual, 'draft-месяц — авто-пересчёт, ручного решения не требует');
        $this->assertSame(400_000_000, (int) GlobalBonusMonth::query()->where('month_period_id', $period->id)->value('global_bv_cents'));

        $note = ReversalAction::query()->where('return_id', $return->id)
            ->where('bonus_type', ReversalAction::BONUS_GLOBAL)->sole();
        $this->assertSame(ReversalAction::TYPE_BONUS_REVERSAL, $note->action_type);
        $this->assertSame('draft_recomputed', $note->snapshot_json['basis']);
        $this->assertFalse($note->snapshot_json['owner_manual']);
    }

    public function testReverseGlobalFinalMonthIsOwnerManualNotAutoPosted(): void
    {
        $policy = $this->activateGlobalBonusPolicy();
        $period = $this->ensurePeriod(self::MONTH);

        $buyer = $this->makeMember();
        $this->seedSnapshot($buyer, '0.000000', 500_000_000, $this->paidAt());
        // Финализированный месяц с зафиксированной member-аллокацией (эмуляция закрытого расчёта).
        $monthId = $this->seedFinalMonth($period, [$buyer => 50_000], $policy->versionId());

        $return = $this->makeReturn($this->orderIdOf($buyer), $buyer, 100_000_000, 'gret-final-1');
        $needsManual = app(BonusReversalService::class)->reverseGlobalForReturn($return);

        // Финализированный месяц: авто-сторно НЕ проводится, требуется ручное решение владельца.
        $this->assertTrue($needsManual);
        // Аллокация НЕ тронута (final_cents прежний), global_bv месяца не пересчитан.
        $this->assertSame(50_000, (int) GlobalBonusAllocation::query()
            ->where('global_bonus_month_id', $monthId)->where('member_id', $buyer)->value('final_cents'));
        $this->assertSame(GlobalBonusAllocation::STATUS_ACCRUED, GlobalBonusAllocation::query()
            ->where('global_bonus_month_id', $monthId)->where('member_id', $buyer)->value('status'));

        // owner-manual note зафиксирован; авто-проводки/выплаты нет.
        $note = ReversalAction::query()->where('return_id', $return->id)
            ->where('bonus_type', ReversalAction::BONUS_GLOBAL)->sole();
        $this->assertSame(ReversalAction::TYPE_QUALIFICATION_NOTE, $note->action_type);
        $this->assertTrue($note->snapshot_json['owner_manual']);
        $this->assertSame(0, DB::table('v2_global_bonus_payouts')->count());
    }
}
