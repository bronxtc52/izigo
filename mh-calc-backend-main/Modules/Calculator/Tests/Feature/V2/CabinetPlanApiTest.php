<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Status;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\AwardEntitlement;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Models\QualificationEvaluation;
use Modules\Calculator\V2\Services\Awards\QualificationAwardService;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;
use Tests\TestCase;

/**
 * mh-full-plan T14 [деньги + права, negative-cases обязательны]: read-эндпоинты таба
 * «Мой план» Mini App. Единый флаг mh_plan_v2_miniapp гейтит все 6 роутов; IDOR —
 * данные только своего участника; лид → 404; account вне os|ns|bs → 422; невалидный
 * cursor — без 500; балансы В ТОЧНОСТИ равны сумме ledger (без float); каталог наград
 * строго из PolicyVersion; скачок рангов → все пройденные earned (DEC-040).
 */
class CabinetPlanApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2Status;

    private CarbonImmutable $at;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        $this->activateV2Policy();
        $this->at = CarbonImmutable::parse('2026-02-01 12:00:00', 'UTC');
    }

    private function wallet(): WalletAccountsV2Service
    {
        return app(WalletAccountsV2Service::class);
    }

    // ------------------------------------------------------------------
    // Права / гейтинг (deny-by-default)
    // ------------------------------------------------------------------

    public function testFlagOffBlocksEveryEndpoint(): void
    {
        [$data] = $this->registerTg(600, name: 'A');
        $h = $this->tgHeaders($data);
        foreach ([
            '/api/v1/cabinet/v2/plan/overview',
            '/api/v1/cabinet/v2/plan/rank-progress',
            '/api/v1/cabinet/v2/plan/accounts',
            '/api/v1/cabinet/v2/plan/accounts/os/lots',
            '/api/v1/cabinet/v2/plan/accounts/os/history',
            '/api/v1/cabinet/v2/plan/awards',
        ] as $url) {
            $this->getJson($url, $h)->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
        }
    }

    public function testRequiresAuth(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        $this->getJson('/api/v1/cabinet/v2/plan/overview', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testLeadGets404(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [, $ref] = $this->registerTg(600, name: 'Sponsor');
        // Лид (валидный initData по реф-ссылке, но ещё не участник) → 404 на плане.
        [$leadData] = $this->makeLead(700, $ref, name: 'Lead');
        foreach ([
            '/api/v1/cabinet/v2/plan/overview',
            '/api/v1/cabinet/v2/plan/rank-progress',
            '/api/v1/cabinet/v2/plan/accounts',
            '/api/v1/cabinet/v2/plan/awards',
        ] as $url) {
            $this->getJson($url, $this->tgHeaders($leadData))->assertStatus(404);
        }
    }

    // ------------------------------------------------------------------
    // Счета — деньги (балансы == ledger, лоты, история, IDOR)
    // ------------------------------------------------------------------

    public function testAccountsBalancesEqualLedgerSum(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$data] = $this->registerTg(600, name: 'A');
        $id = $this->memberByTg(600)->id;

        $this->wallet()->credit($id, 'os', 5000, 'os-1', CarbonImmutable::parse('2026-06-01', 'UTC'), 'structure');
        $this->wallet()->credit($id, 'bs', 3000, 'bs-1', null, 'award'); // без сгорания (MF-9)
        $this->wallet()->credit($id, 'ns', 2000, 'ns-1', null, 'structure', null, '2026-02');

        $resp = $this->getJson('/api/v1/cabinet/v2/plan/accounts', $this->tgHeaders($data))->assertOk();
        $resp->assertJsonPath('data.os_available_cents', 5000);
        $resp->assertJsonPath('data.bs_available_cents', 3000);
        $resp->assertJsonPath('data.ns_cents', 2000);
        $resp->assertJsonPath('data.os_available', '50.00');
        $resp->assertJsonPath('data.order_pay_limit_pct', 70);
        $resp->assertJsonPath('data.currency', 'USD');

        // Точность: баланс кэша == сумма ledger-кредитов по субсчёту (int-центы, без float).
        $osLedger = (int) LedgerEntry::query()->where('member_id', $id)
            ->where('account_type', LedgerPostingV2Service::ACC_OS_AVAILABLE)
            ->where('direction', 'credit')->sum('amount_cents');
        $this->assertSame(5000, $osLedger);
        $this->assertSame(5000, $resp->json('data.os_available_cents'));

        // Ни одного float в денежных полях.
        foreach (['os_available_cents', 'bs_available_cents', 'ns_cents'] as $k) {
            $this->assertIsInt($resp->json("data.$k"));
        }
    }

    public function testLotsEarliestExpiryFirstAndNsEmpty(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$data] = $this->registerTg(600, name: 'A');
        $id = $this->memberByTg(600)->id;
        $h = $this->tgHeaders($data);

        // Два ОС-лота с разными сроками — ожидаем earliest-expiry-first.
        $this->wallet()->credit($id, 'os', 1000, 'os-late', CarbonImmutable::parse('2026-12-01', 'UTC'), 'structure');
        $this->wallet()->credit($id, 'os', 2000, 'os-early', CarbonImmutable::parse('2026-03-01', 'UTC'), 'structure');
        $this->wallet()->credit($id, 'bs', 500, 'bs-1', null, 'award'); // БС бессрочный

        $os = $this->getJson('/api/v1/cabinet/v2/plan/accounts/os/lots', $h)->assertOk();
        $os->assertJsonPath('data.items.0.available_cents', 2000); // ранний срок первым
        $os->assertJsonPath('data.items.1.available_cents', 1000);

        $bs = $this->getJson('/api/v1/cabinet/v2/plan/accounts/bs/lots', $h)->assertOk();
        $bs->assertJsonPath('data.items.0.expires_at', null);       // награда не сгорает
        $bs->assertJsonPath('data.items.0.expiring_soon', false);

        // НС лотов не имеет.
        $this->getJson('/api/v1/cabinet/v2/plan/accounts/ns/lots', $h)
            ->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function testHistoryScopedPerMemberAndCursorStable(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$aData] = $this->registerTg(600, name: 'A');
        [$bData] = $this->registerTg(601, name: 'B');
        $aId = $this->memberByTg(600)->id;
        $bId = $this->memberByTg(601)->id;

        foreach (range(1, 3) as $n) {
            $this->wallet()->credit($aId, 'os', 100 * $n, "a-os-$n", CarbonImmutable::parse('2026-06-01', 'UTC'), 'structure');
        }
        $this->wallet()->credit($bId, 'os', 9999, 'b-os', CarbonImmutable::parse('2026-06-01', 'UTC'), 'structure');

        // A видит только свои 3 движения — не течёт к B.
        $page = $this->getJson('/api/v1/cabinet/v2/plan/accounts/os/history?limit=2', $this->tgHeaders($aData))->assertOk();
        $page->assertJsonCount(2, 'data.items');
        $this->assertNotNull($page->json('data.next_cursor'));
        foreach ($page->json('data.items') as $it) {
            $this->assertNotSame(9999, $it['amount_cents']); // ни одной чужой проводки
        }

        // Вторая страница по курсору — стабильна, без пересечений.
        $cursor = $page->json('data.next_cursor');
        $page2 = $this->getJson("/api/v1/cabinet/v2/plan/accounts/os/history?limit=2&cursor={$cursor}", $this->tgHeaders($aData))->assertOk();
        $page2->assertJsonCount(1, 'data.items');
        $this->assertNull($page2->json('data.next_cursor'));
    }

    public function testInvalidAccountReturns422(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$data] = $this->registerTg(600, name: 'A');
        $h = $this->tgHeaders($data);
        $this->getJson('/api/v1/cabinet/v2/plan/accounts/xyz/lots', $h)->assertStatus(422);
        $this->getJson('/api/v1/cabinet/v2/plan/accounts/xyz/history', $h)->assertStatus(422);
    }

    public function testInvalidCursorDoesNotError(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$data] = $this->registerTg(600, name: 'A');
        // Нечисловой cursor → трактуется как отсутствующий, без 500.
        $this->getJson('/api/v1/cabinet/v2/plan/accounts/os/history?cursor=abc', $this->tgHeaders($data))
            ->assertOk()->assertJsonPath('data.next_cursor', null);
    }

    // ------------------------------------------------------------------
    // Прогресс статусов
    // ------------------------------------------------------------------

    public function testRankProgressLadderAndVariants(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$data] = $this->registerTg(600, name: 'A');
        $id = $this->memberByTg(600)->id;

        // Текущий ранг SILVER_MANAGER (ordinal 4); достигнутые CONSULTANT..SILVER.
        $this->seedPartnerState($id, PartnerState::STATE_CONSULTANT, StatusCode::SILVER_MANAGER);
        PartnerState::query()->where('member_id', $id)->update(['current_tier' => 'BUSINESS', 'personal_pv_total' => '250.000000']);
        foreach ([StatusCode::CONSULTANT, StatusCode::MANAGER, StatusCode::BRONZE_MANAGER, StatusCode::SILVER_MANAGER] as $r) {
            $this->seedRank($id, $r, $this->at);
        }

        // Снапшот оценки следующего статуса GOLD_MANAGER (3 варианта V1/V2/V3), не пройден.
        QualificationEvaluation::query()->create([
            'id' => (string) Str::uuid(),
            'member_id' => $id,
            'target_rank_code' => 'GOLD_MANAGER',
            'as_of' => $this->at,
            'policy_version_id' => 1,
            'small_branch_pv' => '12000.000000',
            'variant_used' => null,
            'passed' => false,
            'qualifiers_json' => [],
            'criteria_json' => [
                ['rule_id' => 'small_branch_pv', 'actual' => '12000.000000', 'required' => 20000, 'passed' => false, 'reason' => 'SMALL_BRANCH_PV_BELOW_THRESHOLD'],
                ['rule_id' => 'variant_V1', 'actual' => 1, 'required' => 2, 'passed' => false, 'reason' => 'NO_DISTINCT_BRANCH_ASSIGNMENT'],
                ['rule_id' => 'variant_V2', 'actual' => 0, 'required' => 5, 'passed' => false, 'reason' => 'NO_DISTINCT_BRANCH_ASSIGNMENT'],
                ['rule_id' => 'variant_V3', 'actual' => 0, 'required' => 8, 'passed' => false, 'reason' => 'NO_DISTINCT_BRANCH_ASSIGNMENT'],
            ],
            'evidence_hash' => 'test',
            'trigger' => QualificationEvaluation::TRIGGER_ORDER,
            'created_at' => now(),
        ]);

        $resp = $this->getJson('/api/v1/cabinet/v2/plan/rank-progress', $this->tgHeaders($data))->assertOk();

        // Лестница из 12 статусов, SILVER — current и achieved, GOLD — ещё нет.
        $resp->assertJsonCount(12, 'data.ladder');
        $ladder = collect($resp->json('data.ladder'))->keyBy('code');
        $this->assertTrue($ladder['SILVER_MANAGER']['is_current']);
        $this->assertTrue($ladder['SILVER_MANAGER']['achieved']);
        $this->assertTrue($ladder['MANAGER']['achieved']);
        $this->assertFalse($ladder['GOLD_MANAGER']['achieved']);

        // Следующий статус GOLD с 3 вариантами + прогресс малой ветки.
        $resp->assertJsonPath('data.next.rank_code', 'GOLD_MANAGER');
        $resp->assertJsonPath('data.next.source', 'evaluation');
        $resp->assertJsonPath('data.next.small_branch_pv.required', 20000);
        $resp->assertJsonPath('data.next.small_branch_pv.actual', '12000.000000');
        $this->assertCount(3, $resp->json('data.next.variants'));
        $variants = collect($resp->json('data.next.variants'))->keyBy('code');
        $this->assertSame(2, $variants['V1']['required_slots']);
        $this->assertSame(1, $variants['V1']['actual_slots']);
        $this->assertFalse($variants['V1']['distinct_root_branches']);
        $this->assertTrue($variants['V2']['distinct_root_branches']);

        // Тир + порог.
        $resp->assertJsonPath('data.tier.code', 'BUSINESS');
        $resp->assertJsonPath('data.tier.personal_pv', '250.000000');
    }

    public function testRankForeverNotRegressed(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$data] = $this->registerTg(600, name: 'A');
        $id = $this->memberByTg(600)->id;
        // Ранг достигнут; объёмы «упали» — но проекция ранг не понижает (DEC-020).
        $this->seedPartnerState($id, PartnerState::STATE_CONSULTANT, StatusCode::DIRECTOR);
        $this->seedRank($id, StatusCode::DIRECTOR, $this->at);

        $resp = $this->getJson('/api/v1/cabinet/v2/plan/rank-progress', $this->tgHeaders($data))->assertOk();
        $resp->assertJsonPath('data.current_rank_code', 'DIRECTOR');
        $ladder = collect($resp->json('data.ladder'))->keyBy('code');
        $this->assertTrue($ladder['DIRECTOR']['achieved']);
        $this->assertTrue($ladder['DIRECTOR']['is_current']);
    }

    // ------------------------------------------------------------------
    // Награды
    // ------------------------------------------------------------------

    public function testAwardsCatalogAmountsFromPolicy(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$data] = $this->registerTg(600, name: 'A');

        $resp = $this->getJson('/api/v1/cabinet/v2/plan/awards', $this->tgHeaders($data))->assertOk();
        $items = collect($resp->json('data.items'))->keyBy(fn ($i) => $i['award_code'] . ':' . $i['stage_no']);
        // Суммы строго из PolicyVersion (byStatusCents).
        $this->assertSame(10000, $items['MANAGER:1']['amount_cents']);
        $this->assertSame(250000, $items['DIRECTOR:1']['amount_cents']);
        // Ничего не достигнуто → всё locked.
        $this->assertSame('locked', $items['MANAGER:1']['state']);
        // VP тремя траншами.
        $this->assertSame(5000000, $items['VICE_PRESIDENT:1']['amount_cents']);
        $this->assertTrue($items->has('VICE_PRESIDENT:3'));
    }

    public function testAwardsJumpShowsAllCrossedEarned(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$data] = $this->registerTg(600, name: 'A');
        $id = $this->memberByTg(600)->id;
        // Скачок Consultant→Bronze: rank_history содержит все пройденные ранги (DEC-040),
        // entitlement'ов ещё нет → и Manager, и Bronze earned.
        $this->seedRank($id, StatusCode::MANAGER, $this->at);
        $this->seedRank($id, StatusCode::BRONZE_MANAGER, $this->at);

        $resp = $this->getJson('/api/v1/cabinet/v2/plan/awards', $this->tgHeaders($data))->assertOk();
        $items = collect($resp->json('data.items'))->keyBy(fn ($i) => $i['award_code'] . ':' . $i['stage_no']);
        $this->assertSame('earned', $items['MANAGER:1']['state']);
        $this->assertSame('earned', $items['BRONZE_MANAGER:1']['state']);
        // Не пройденный выше — locked.
        $this->assertSame('locked', $items['DIRECTOR:1']['state']);
    }

    public function testAwardStateReflectsEntitlement(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$data] = $this->registerTg(600, name: 'A');
        $id = $this->memberByTg(600)->id;
        // Реальный entitlement T10 (granted, начислен на БС) — источник истины.
        $this->seedRank($id, StatusCode::MANAGER, $this->at);
        app(QualificationAwardService::class)->reconcileMemberFromRankHistory($id, $this->at);

        $resp = $this->getJson('/api/v1/cabinet/v2/plan/awards', $this->tgHeaders($data))->assertOk();
        $items = collect($resp->json('data.items'))->keyBy(fn ($i) => $i['award_code'] . ':' . $i['stage_no']);
        $this->assertSame(AwardEntitlement::STATUS_GRANTED, $items['MANAGER:1']['state']);
        $this->assertSame(AwardEntitlement::STATUS_GRANTED, $items['MANAGER:1']['entitlement_status']);
    }

    // ------------------------------------------------------------------
    // Overview — нет второй правды
    // ------------------------------------------------------------------

    public function testOverviewMatchesDetailEndpoints(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$data] = $this->registerTg(600, name: 'A');
        $id = $this->memberByTg(600)->id;
        $h = $this->tgHeaders($data);
        $this->seedPartnerState($id, PartnerState::STATE_CONSULTANT, StatusCode::MANAGER);
        PartnerState::query()->where('member_id', $id)->update(['current_tier' => 'START']);
        $this->wallet()->credit($id, 'os', 4200, 'os-1', CarbonImmutable::parse('2026-06-01', 'UTC'), 'structure');

        $overview = $this->getJson('/api/v1/cabinet/v2/plan/overview', $h)->assertOk();
        $accounts = $this->getJson('/api/v1/cabinet/v2/plan/accounts', $h)->assertOk();

        $overview->assertJsonPath('data.rank_code', 'MANAGER');
        $overview->assertJsonPath('data.tier_code', 'START');
        // Балансы overview == детальный /accounts (одна правда).
        $this->assertSame($accounts->json('data.os_available_cents'), $overview->json('data.accounts.os_available_cents'));
        $this->assertSame(4200, $overview->json('data.accounts.os_available_cents'));
    }
}
