<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\PolicyVersion;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Modules\Calculator\V2\Services\Periods\PeriodService;
use Modules\Calculator\V2\Services\PolicyVersionService;
use Modules\Calculator\V2\Services\Volume\PolicyVersionIdProvider;
use Tests\TestCase;

/**
 * MF-1/MF-2 (ревью W1): potребители контракта T01 обязаны читать РЕАЛЬНЫЙ API
 * PolicyV2 (versionId()/configHash()), а не несуществующие свойства id/config_hash.
 * Интеграция с реальным PolicyVersionService: после активации версии provenance
 * лотов (T03), периодов и снапшотов (T04) получает настоящие id/hash; fallback —
 * ТОЛЬКО когда активной версии нет.
 */
class PolicyProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-07-20 00:10:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** Активировать канонический сид-draft ретроактивно (действует на весь 2026 год). */
    private function activateSeededVersion(): PolicyVersion
    {
        $version = PolicyVersion::query()->where('code', DefaultPolicyConfig::CODE)->firstOrFail();
        app(PolicyVersionService::class)->activate(
            $version->id, null, Carbon::parse('2026-01-01 00:00:00', 'UTC'), allowRetro: true
        );

        return $version->refresh();
    }

    public function testVolumeProviderStampsActivatedVersionId(): void
    {
        $version = $this->activateSeededVersion();

        $this->assertSame(
            $version->id,
            app(PolicyVersionIdProvider::class)->forDate(now()),
            'MF-1: provenance лотов должен получать versionId() активной политики, а не fallback'
        );
    }

    public function testVolumeProviderFallsBackOnlyWithoutActiveVersion(): void
    {
        // Версия не активирована — единственный легитимный случай fallback'а.
        $this->assertSame(
            PolicyVersionIdProvider::FALLBACK_VERSION_ID,
            app(PolicyVersionIdProvider::class)->forDate(now())
        );
    }

    public function testPeriodRowStampsActivatedVersionId(): void
    {
        $version = $this->activateSeededVersion();

        $period = app(PeriodService::class)->ensureByCode('2026-07-H1');

        $this->assertSame(
            $version->id,
            $period->policy_version_id,
            'MF-2: v2_calc_periods.policy_version_id должен фиксировать активную версию'
        );
    }

    public function testSnapshotPolicySectionStampsRealProvenance(): void
    {
        $version = $this->activateSeededVersion();

        $run = app(PeriodCloseService::class)->runPreview('2026-07-H1');
        $payload = $run->snapshot()->sole()->payload;

        $this->assertSame($version->id, $payload['policy']['policy_version_id'], 'MF-2: снапшот без versionId() политики');
        $this->assertSame($version->config_hash, $payload['policy']['config_hash'], 'MF-2: снапшот без configHash() политики');
    }

    public function testClosedPeriodSnapshotKeepsProvenance(): void
    {
        // Боевое закрытие H1 (окно уже истекло к 20-му числу) — секция policy в снапшоте
        // закрытия обязана нести реальные id/hash (аудит расчётов T06–T11).
        $version = $this->activateSeededVersion();

        app(PeriodCloseService::class)->closeHalfMonth('2026-07-H1');

        $period = app(PeriodService::class)->findByCode('2026-07-H1');
        $this->assertSame(CalcPeriod::STATUS_CLOSED, $period->status);
        $payload = $period->runs()->sole()->snapshot()->sole()->payload;
        $this->assertSame($version->id, $payload['policy']['policy_version_id']);
        $this->assertSame($version->config_hash, $payload['policy']['config_hash']);
    }
}
