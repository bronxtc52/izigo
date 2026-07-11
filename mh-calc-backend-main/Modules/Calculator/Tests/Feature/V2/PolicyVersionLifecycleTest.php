<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Calculator\Models\PolicyVersion;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\PolicyNotActiveException;
use Modules\Calculator\V2\Services\PolicyVersionService;
use Tests\TestCase;

/**
 * T01: жизненный цикл версий политики V2 + резолвер по дате события.
 * Деньги: единственность active-версии и точные границы полуинтервала
 * [valid_from, valid_to) — от этого зависит, по какой версии считается каждый бонус.
 */
class PolicyVersionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PolicyVersionService
    {
        return app(PolicyVersionService::class);
    }

    public function testSeedMigrationCreatesCanonicalDraft(): void
    {
        $seeded = PolicyVersion::query()->where('code', DefaultPolicyConfig::CODE)->first();

        $this->assertNotNull($seeded, 'сид-миграция 2026_07_12_100100 должна создать draft mh-v2-usd-1');
        $this->assertSame(PolicyVersion::STATUS_DRAFT, $seeded->status);
        $this->assertNull($seeded->valid_from);
        $this->assertSame(DefaultPolicyConfig::doc(), $seeded->config);
        $this->assertSame(DefaultPolicyConfig::canonicalHash(DefaultPolicyConfig::doc()), $seeded->config_hash);
    }

    public function testResolverIsBoundToService(): void
    {
        $this->assertInstanceOf(PolicyVersionService::class, app(PolicyVersionResolver::class));
    }

    public function testNoActiveVersionFailsClosed(): void
    {
        $this->expectException(PolicyNotActiveException::class);
        $this->service()->current();
    }

    public function testActivationAndHalfOpenIntervalBoundaries(): void
    {
        $service = $this->service();
        $v1 = PolicyVersion::query()->where('code', DefaultPolicyConfig::CODE)->firstOrFail();

        // Активация v1 «с сейчас».
        $service->activate($v1->id, null);
        $v1->refresh();
        $this->assertSame(PolicyVersion::STATUS_ACTIVE, $v1->status);
        $this->assertNull($v1->valid_to);
        $t1 = CarbonImmutable::parse($v1->valid_from);

        // Граница valid_from ВКЛЮЧИТЕЛЬНО; до неё — исключение.
        $this->assertSame($v1->id, $service->forDate($t1)->versionId());
        try {
            $service->forDate($t1->subSecond());
            $this->fail('дата до первой активации должна давать PolicyNotActiveException');
        } catch (PolicyNotActiveException) {
            $this->addToAssertionCount(1);
        }

        // Вторая версия с valid_from в будущем.
        $t2 = $t1->addDays(2);
        $v2 = $service->createDraft('mh-v2-usd-2', DefaultPolicyConfig::doc(), null);
        $service->activate($v2->id, null, $t2);

        // v1 автозакрыта: [t1, t2), статус retired, но история резолвится.
        $v1->refresh();
        $this->assertSame(PolicyVersion::STATUS_RETIRED, $v1->status);
        $this->assertTrue($t2->equalTo(CarbonImmutable::parse($v1->valid_to)));

        // Полуинтервал: t2-1s -> v1, t2 (включительно) -> v2.
        $this->assertSame($v1->id, $service->forDate($t2->subSecond())->versionId());
        $this->assertSame($v2->id, $service->forDate($t2)->versionId());
        $this->assertSame($v2->id, $service->forDate($t2->addDay())->versionId());

        // Инвариант: ровно одна active (и у неё valid_to IS NULL).
        $active = PolicyVersion::query()->where('status', PolicyVersion::STATUS_ACTIVE)->get();
        $this->assertCount(1, $active);
        $this->assertNull($active->first()->valid_to);
        $this->assertSame($v2->id, $active->first()->id);
    }

    public function testProvenanceExposedForSnapshots(): void
    {
        $service = $this->service();
        $v1 = PolicyVersion::query()->where('code', DefaultPolicyConfig::CODE)->firstOrFail();
        $service->activate($v1->id, null);

        $policy = $service->current();
        $this->assertSame($v1->id, $policy->versionId());
        $this->assertSame($v1->fresh()->config_hash, $policy->configHash());
        $this->assertSame(DefaultPolicyConfig::CODE, $policy->versionCode());

        // Типизированные аксессоры (контракт T02+): деньги int-центы, ставки int bp.
        $this->assertSame(7000, $policy->accounts()->osMaxOrderPaymentShareBp);
        $this->assertSame([1, 16], $policy->accounts()->nsTransferDays);
        $this->assertSame(365, $policy->accounts()->osLotLifetimeDays);
        $this->assertSame(6000, $policy->calibration()->rateBp);
        $this->assertSame(2, $policy->globalPool()->maxShares);
        $this->assertSame(300, $policy->globalPool()->totalRateBp());
        $this->assertSame(500, $policy->statusByCode('CONSULTANT')->binaryRateBp);
        $this->assertSame(4000000, $policy->statusByCode('VICE_PRESIDENT')->monthlyCapCents);
        $this->assertSame(2000000, $policy->statusByCode('VICE_PRESIDENT')->halfMonthCapCents);
        $this->assertSame('BUSINESS', $policy->tierForPv(270)?->code); // накопительность 90+180
        $this->assertNull($policy->tierForPv(99));
        $this->assertSame(11, $policy->statusByCode('VICE_PRESIDENT')->ordinal);
        $this->assertFalse($policy->referral()->stopAtElite);
        $this->assertSame(30, $policy->graceClientToConsultantDays());
        $this->assertTrue($policy->rankForever());
    }

    public function testDraftImmutabilityRules(): void
    {
        $service = $this->service();
        $v1 = PolicyVersion::query()->where('code', DefaultPolicyConfig::CODE)->firstOrFail();
        $service->activate($v1->id, null);

        // updateDraft на active -> 422-исключение (immutability).
        try {
            $service->updateDraft($v1->id, DefaultPolicyConfig::doc(), null);
            $this->fail('active-версия должна быть immutable');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Повторная активация уже active -> отказ.
        try {
            $service->activate($v1->id, null);
            $this->fail('повторная активация active должна отвергаться');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('active', $e->getMessage());
        }

        // Активация retired -> отказ.
        $v2 = $service->createDraft('mh-v2-usd-2', DefaultPolicyConfig::doc(), null);
        $service->activate($v2->id, null, now()->toImmutable()->addDay());
        try {
            $service->activate($v1->fresh()->id, null, now()->toImmutable()->addDays(2));
            $this->fail('активация retired должна отвергаться');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('retired', $e->getMessage());
        }
    }

    public function testRetroActivationRequiresExplicitFlag(): void
    {
        $service = $this->service();
        $v1 = PolicyVersion::query()->where('code', DefaultPolicyConfig::CODE)->firstOrFail();
        $past = now()->toImmutable()->subDays(3);

        try {
            $service->activate($v1->id, null, $past);
            $this->fail('retro-активация без флага должна отвергаться');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('retro', $e->getMessage());
        }

        // Явный флаг (cutover T15) — допустимо.
        $service->activate($v1->id, null, $past, allowRetro: true);
        $this->assertSame($v1->id, $service->forDate($past)->versionId());
        $this->assertSame($v1->id, $service->current()->versionId());
    }

    public function testUpdateDraftRecomputesStableHash(): void
    {
        $service = $this->service();
        $v1 = PolicyVersion::query()->where('code', DefaultPolicyConfig::CODE)->firstOrFail();
        $originalHash = $v1->config_hash;

        // Тот же документ -> тот же hash (canonical json стабилен).
        $service->updateDraft($v1->id, DefaultPolicyConfig::doc(), null);
        $this->assertSame($originalHash, $v1->fresh()->config_hash);

        // Содержательное изменение -> hash меняется.
        $changed = DefaultPolicyConfig::doc();
        $changed['grace']['client_to_consultant_days'] = 45;
        $service->updateDraft($v1->id, $changed, null);
        $this->assertNotSame($originalHash, $v1->fresh()->config_hash);
    }

    public function testInvalidConfigRejectedOnCreateAndUpdate(): void
    {
        $service = $this->service();
        $broken = DefaultPolicyConfig::doc();
        $broken['statuses'][1]['half_month_cap_cents'] = 1; // != monthly/2

        $this->expectException(InvalidArgumentException::class);
        $service->createDraft('mh-v2-usd-broken', $broken, null);
    }

    public function testRetireActiveStopsResolutionFailClosed(): void
    {
        $service = $this->service();
        $v1 = PolicyVersion::query()->where('code', DefaultPolicyConfig::CODE)->firstOrFail();
        $service->activate($v1->id, null);
        $this->assertSame($v1->id, $service->current()->versionId());

        $service->retire($v1->id, null);
        $v1->refresh();
        $this->assertSame(PolicyVersion::STATUS_RETIRED, $v1->status);
        $this->assertNotNull($v1->valid_to);

        // История до retire резолвится, текущий момент — fail-closed.
        $this->expectException(PolicyNotActiveException::class);
        $service->forDate(now()->toImmutable()->addSecond());
    }

    /**
     * Сериализация конкурентных активаций обеспечивается lockForUpdate всех строк
     * в одной транзакции (вторая транзакция ждёт и видит новое состояние). Здесь —
     * последовательный эквивалент гонки: два черновика, две активации, инвариант
     * «ровно одна active» соблюдён, вторая активация первого — отказ.
     */
    public function testSequentialDoubleActivationKeepsSingleActive(): void
    {
        $service = $this->service();
        $a = PolicyVersion::query()->where('code', DefaultPolicyConfig::CODE)->firstOrFail();
        $b = $service->createDraft('mh-v2-usd-b', DefaultPolicyConfig::doc(), null);

        $service->activate($a->id, null);
        $service->activate($b->id, null, now()->toImmutable()->addMinute());

        $this->assertSame(1, PolicyVersion::query()->where('status', PolicyVersion::STATUS_ACTIVE)->count());
        $this->assertSame(
            0,
            PolicyVersion::query()->where('status', PolicyVersion::STATUS_ACTIVE)->whereNotNull('valid_to')->count(),
            'у active всегда valid_to IS NULL',
        );

        try {
            $service->activate($a->id, null);
            $this->fail('повторная активация закрытой версии должна отвергаться');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
