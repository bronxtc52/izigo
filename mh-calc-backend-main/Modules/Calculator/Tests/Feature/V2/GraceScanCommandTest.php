<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Status;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Services\Status\ClientLifecycleService;
use Tests\TestCase;

/**
 * T05 [ДЕНЬГИ]: сканер просроченного grace (calc-v2:client-grace-scan, MF-7).
 * Батч просроченных => каждый аннулирован ровно раз; повторный прогон => 0;
 * флаг OFF => no-op; grace_expired участник НЕ становится CONSULTANT сам — только
 * при появлении квалифицированного реферала (BR-REG-004), но аннулированные PV
 * не восстанавливаются.
 */
class GraceScanCommandTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2Status;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        $this->activateV2Policy();
    }

    /** CLIENT в grace с истёкшим дедлайном (без PV-лотов — аннулирование = no-op по лотам). */
    private function seedExpiredClient(int $tgId, ?string $sponsorRef = null): int
    {
        $this->registerTg($tgId, $sponsorRef, "U{$tgId}");
        $id = $this->memberByTg($tgId)->id;
        PartnerState::query()->updateOrCreate(['member_id' => $id], [
            'state' => PartnerState::STATE_CLIENT,
            'current_rank_code' => 'CLIENT',
            'personal_pv_total' => '100',
            'client_achieved_at' => CarbonImmutable::parse('2026-06-01', 'UTC'),
            'grace_started_at' => CarbonImmutable::parse('2026-06-01', 'UTC'),
            'grace_expires_at' => CarbonImmutable::parse('2026-07-01 23:59:59', 'UTC'),
            'grace_outcome' => null,
        ]);

        return $id;
    }

    public function testFlagOffIsNoop(): void
    {
        $id = $this->seedExpiredClient(600);
        $this->travelTo(CarbonImmutable::parse('2026-08-01', 'UTC'));
        $this->artisan('calc-v2:client-grace-scan')->assertSuccessful();

        // Флаг mh_v2_statuses OFF => участник не тронут.
        $this->assertSame(PartnerState::STATE_CLIENT, PartnerState::query()->find($id)->state);
        $this->travelBack();
    }

    public function testBatchExpiryThenIdempotentRerun(): void
    {
        $this->enableFeatureFlags('mh_v2_statuses');
        $ids = [$this->seedExpiredClient(600), $this->seedExpiredClient(601), $this->seedExpiredClient(602)];
        // Не истёкший CLIENT — не должен пострадать.
        $this->registerTg(700, name: 'Fresh');
        $freshId = $this->memberByTg(700)->id;
        PartnerState::query()->create([
            'member_id' => $freshId, 'state' => PartnerState::STATE_CLIENT, 'personal_pv_total' => '100',
            'grace_expires_at' => CarbonImmutable::parse('2026-12-31', 'UTC'), 'grace_outcome' => null,
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-08-01', 'UTC'));
        $this->artisan('calc-v2:client-grace-scan')->assertSuccessful();

        foreach ($ids as $id) {
            $state = PartnerState::query()->find($id);
            $this->assertSame(PartnerState::STATE_GRACE_EXPIRED, $state->state);
            $this->assertSame(PartnerState::OUTCOME_ANNULLED, $state->grace_outcome);
        }
        $this->assertSame(PartnerState::STATE_CLIENT, PartnerState::query()->find($freshId)->state);

        // Повторный прогон — 0 просроченных (grace_outcome уже annulled).
        $this->artisan('calc-v2:client-grace-scan')->expectsOutputToContain('аннулировано 0')->assertSuccessful();
        $this->travelBack();
    }

    public function testExpiredClientBecomesConsultantOnlyViaLaterReferralWithoutPvRestore(): void
    {
        $this->enableFeatureFlags('mh_v2_statuses');
        $rootId = $this->seedExpiredClient(600);
        $rootRef = $this->memberByTg(600)->ref_code;

        $this->travelTo(CarbonImmutable::parse('2026-08-01', 'UTC'));
        $this->artisan('calc-v2:client-grace-scan')->assertSuccessful();
        $this->assertSame(PartnerState::STATE_GRACE_EXPIRED, PartnerState::query()->find($rootId)->state);

        // Появился личный реферал ПОСЛЕ просрочки — лестница продолжается (CONSULTANT),
        // но аннулированные PV не восстанавливаются (grace_outcome остаётся annulled).
        $this->registerTg(601, $rootRef, 'Late');
        $refId = $this->memberByTg(601)->id;
        $lifecycle = app(ClientLifecycleService::class);
        $this->underActivationLock(fn () => $lifecycle->onPersonalReferralActivated(
            $rootId, $refId, CarbonImmutable::now(), $this->policy()
        ));

        $state = PartnerState::query()->find($rootId);
        $this->assertSame(PartnerState::STATE_CONSULTANT, $state->state);
        $this->assertSame(PartnerState::OUTCOME_ANNULLED, $state->grace_outcome); // PV не воскресают
        $this->travelBack();
    }
}
