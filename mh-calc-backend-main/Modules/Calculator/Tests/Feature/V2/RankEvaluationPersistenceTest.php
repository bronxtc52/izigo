<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Status;
use Modules\Calculator\V2\Contracts\BinaryVolumeReaderInterface;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Models\QualificationEvaluation;
use Modules\Calculator\V2\Models\RankHistory;
use Modules\Calculator\V2\Services\Status\RankEvaluationService;
use Tests\TestCase;

/**
 * T05 [ДЕНЬГИ/КАРЬЕРА]: персистентность оценки лестницы. Скачок пишет строку на
 * КАЖДЫЙ пройденный ранг с одним evaluation_id (DEC-040, контракт наград T10);
 * снапшот квалификации с qualifiers/root-ветвями; идемпотентность (unique guard);
 * оценка всего sponsor-аплайна. BinaryVolumeReader подменён фейком (lifetime PV
 * контролируем без реальных лотов/FK).
 */
class RankEvaluationPersistenceTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2Status;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        $this->activateV2Policy();
        $this->now = CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC');
    }

    /** Фейк lifetime PV: одинаковый на обе стороны (малая ветка = это значение). */
    private function fakeVolume(string $pvBothSides): void
    {
        $this->app->bind(BinaryVolumeReaderInterface::class, fn () => new class($pvBothSides) implements BinaryVolumeReaderInterface {
            public function __construct(private readonly string $pv)
            {
            }

            public function leftLifetimePv(int $memberId, \DateTimeInterface $asOf): string
            {
                return $this->pv;
            }

            public function rightLifetimePv(int $memberId, \DateTimeInterface $asOf): string
            {
                return $this->pv;
            }
        });
    }

    private function service(): RankEvaluationService
    {
        return app(RankEvaluationService::class);
    }

    public function testRankJumpWritesEveryCrossedRankWithOneEvaluationId(): void
    {
        // Малая ветка 3000 + 8 квалифицированных L1 рефералов => скачок Consultant -> BRONZE_MANAGER.
        $this->fakeVolume('3000');
        [, $rootRef] = $this->registerTg(700, name: 'Root');
        $rootId = $this->memberByTg(700)->id;
        $this->seedPartnerState($rootId, PartnerState::STATE_CONSULTANT, StatusCode::CONSULTANT);

        for ($i = 1; $i <= 8; $i++) {
            $this->registerTg(700 + $i, $rootRef, "R{$i}");
            $childId = $this->memberByTg(700 + $i)->id;
            $this->seedPartnerState($childId, PartnerState::STATE_CLIENT, StatusCode::CLIENT, $this->now->subDay());
        }

        $new = $this->underActivationLock(fn () => $this->service()->evaluateMember(
            $rootId, $this->now, $this->policy(), QualificationEvaluation::TRIGGER_MANUAL
        ));

        $this->assertSame(['MANAGER', 'BRONZE_MANAGER'], $new);

        $rows = RankHistory::query()->where('member_id', $rootId)->orderBy('rank_ordinal')->get();
        $this->assertSame(
            ['MANAGER', 'BRONZE_MANAGER'],
            $rows->pluck('rank_code')->all()
        );
        // Обе строки — с ОДНИМ evaluation_id (единый триггер награды T10, DEC-040).
        $this->assertCount(1, $rows->pluck('evaluation_id')->unique());
        $this->assertNotNull($rows->first()->evaluation_id);

        $this->assertSame('BRONZE_MANAGER', PartnerState::query()->find($rootId)->current_rank_code);
    }

    public function testRepeatEvaluationNoDuplicateRankRows(): void
    {
        $this->fakeVolume('3000');
        [, $rootRef] = $this->registerTg(700, name: 'Root');
        $rootId = $this->memberByTg(700)->id;
        $this->seedPartnerState($rootId, PartnerState::STATE_CONSULTANT, StatusCode::CONSULTANT);
        for ($i = 1; $i <= 8; $i++) {
            $this->registerTg(700 + $i, $rootRef, "R{$i}");
            $this->seedPartnerState($this->memberByTg(700 + $i)->id, PartnerState::STATE_CLIENT, StatusCode::CLIENT, $this->now->subDay());
        }

        $this->underActivationLock(fn () => $this->service()->evaluateMember($rootId, $this->now, $this->policy(), 'manual'));
        $countAfterFirst = RankHistory::query()->where('member_id', $rootId)->count();

        // Повторная оценка без изменений сети — passed, но НИ ОДНОЙ новой rank-строки.
        $new = $this->underActivationLock(fn () => $this->service()->evaluateMember($rootId, $this->now, $this->policy(), 'manual'));
        $this->assertSame([], $new);
        $this->assertSame($countAfterFirst, RankHistory::query()->where('member_id', $rootId)->count());
    }

    public function testVariantEvaluationSnapshotHasQualifiersAndRootBranches(): void
    {
        // SILVER_MANAGER: малая ветка 8000 + 3 MANAGER на L1 (V1 anchor_count=3).
        $this->fakeVolume('8000');
        [, $rootRef] = $this->registerTg(700, name: 'Root');
        $rootId = $this->memberByTg(700)->id;
        $this->seedPartnerState($rootId, PartnerState::STATE_CONSULTANT, StatusCode::CONSULTANT);

        for ($i = 1; $i <= 3; $i++) {
            $this->registerTg(700 + $i, $rootRef, "M{$i}");
            $childId = $this->memberByTg(700 + $i)->id;
            $this->seedPartnerState($childId, PartnerState::STATE_CONSULTANT, StatusCode::MANAGER);
            $this->seedRank($childId, StatusCode::MANAGER, $this->now->subDay());
        }

        $this->underActivationLock(fn () => $this->service()->evaluateMember($rootId, $this->now, $this->policy(), 'manual'));

        $eval = QualificationEvaluation::query()
            ->where('member_id', $rootId)->where('passed', true)
            ->orderByDesc('created_at')->first();
        $this->assertNotNull($eval);
        $this->assertSame('SILVER_MANAGER', $eval->target_rank_code);
        $this->assertSame('V1', $eval->variant_used);
        $this->assertCount(3, $eval->qualifiers_json);
        foreach ($eval->qualifiers_json as $q) {
            $this->assertArrayHasKey('root_branch_member_id', $q);
            $this->assertArrayHasKey('rank_code_as_of', $q);
            $this->assertSame('MANAGER', $q['rank_code_as_of']);
        }
        $this->assertNotEmpty($eval->evidence_hash);

        // Скачок Consultant -> Silver: строки MANAGER, BRONZE_MANAGER, SILVER_MANAGER (DEC-040).
        $this->assertEqualsCanonicalizing(
            ['MANAGER', 'BRONZE_MANAGER', 'SILVER_MANAGER'],
            RankHistory::query()->where('member_id', $rootId)->pluck('rank_code')->all(),
        );
    }

    public function testFailedEvaluationPersistedWithoutRankRows(): void
    {
        // Малая ветка мала (500) — MANAGER недостижим; оценка сохраняется как passed=false.
        $this->fakeVolume('500');
        $this->registerTg(700, name: 'Root');
        $rootId = $this->memberByTg(700)->id;
        $this->seedPartnerState($rootId, PartnerState::STATE_CONSULTANT, StatusCode::CONSULTANT);

        $new = $this->underActivationLock(fn () => $this->service()->evaluateMember($rootId, $this->now, $this->policy(), 'manual'));
        $this->assertSame([], $new);
        $this->assertSame(0, RankHistory::query()->where('member_id', $rootId)->count());
        $eval = QualificationEvaluation::query()->where('member_id', $rootId)->first();
        $this->assertNotNull($eval);
        $this->assertFalse((bool) $eval->passed);
    }

    public function testEvaluateAffectedUplineWalksSponsorChain(): void
    {
        // root <- A <- B (sponsor_id-цепочка). Оценка B триггерит evaluation всех предков.
        $this->fakeVolume('100');
        [, $rootRef] = $this->registerTg(700, name: 'Root');
        [, $aRef] = $this->registerTg(701, $rootRef, 'A');
        $this->registerTg(702, $aRef, 'B');
        foreach ([700, 701, 702] as $tg) {
            $this->seedPartnerState($this->memberByTg($tg)->id, PartnerState::STATE_CONSULTANT, StatusCode::CONSULTANT);
        }

        $bId = $this->memberByTg(702)->id;
        $this->underActivationLock(fn () => $this->service()->evaluateAffectedUpline($bId, $this->now, $this->policy()));

        foreach ([700, 701, 702] as $tg) {
            $id = $this->memberByTg($tg)->id;
            $this->assertGreaterThanOrEqual(1, QualificationEvaluation::query()->where('member_id', $id)->count(),
                "Ожидалась оценка для участника tg={$tg}");
        }
    }
}
