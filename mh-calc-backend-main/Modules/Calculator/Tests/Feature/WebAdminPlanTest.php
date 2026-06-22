<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Domain\Plan\IziGoPlanFactory;
use Modules\Calculator\Repositories\EloquentPlanRepository;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Редактирование маркетинг-плана из веб-админки (GET/PUT /admin/plan) + аудит-лог.
 * Покрывает: чтение полного документа (дефолты), запись с применением к комп-движку
 * (forward-only через load()), валидацию диапазонов, RBAC (owner правит, finance нет),
 * аудит изменений плана и ролей.
 */
class WebAdminPlanTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** [ownerInitData] владельца + finance готовы. */
    private function bootOwnerAndFinance(): array
    {
        [$ownerData, $ownerRef] = $this->registerTg(400, name: 'Owner');
        $this->grantRole(400, 'owner');
        [$financeData] = $this->registerTg(401, $ownerRef, 'Finance');
        $this->grantRole(401, 'finance');

        return [$ownerData, $financeData];
    }

    public function testGetPlanReturnsDefaults(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        $res = $this->getJson('/api/v1/admin/plan', $this->adminHeaders($ownerData))->assertOk();

        $this->assertSame(5, $res->json('data.binary_percent_by_rank.1'));
        $this->assertCount(3, $res->json('data.packages'));
        $this->assertCount(4, $res->json('data.ranks'));
        $this->assertSame(2, $res->json('data.global.referral_depth'));
    }

    public function testOwnerUpdatesPlanAndEngineReflectsIt(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        $doc = IziGoPlanFactory::defaults();
        $doc['binary_percent_by_rank'][1] = 7; // меняем бинарный % для ранга 1

        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($ownerData))
            ->assertOk()
            ->assertJsonPath('data.binary_percent_by_rank.1', 7);

        // Комп-движок читает план на момент расчёта (forward-only).
        $plan = app(EloquentPlanRepository::class)->load();
        $this->assertSame(7.0, $plan->binaryPercent(1)->value);
    }

    public function testInvalidPercentRejected(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        $doc = IziGoPlanFactory::defaults();
        $doc['binary_percent_by_rank'][1] = 150; // вне 0–100

        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($ownerData))->assertStatus(422);
    }

    public function testNegativePvRejected(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        $doc = IziGoPlanFactory::defaults();
        $doc['packages'][0]['pv'] = -10;

        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($ownerData))->assertStatus(422);
    }

    public function testMissingSectionRejected(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        $doc = IziGoPlanFactory::defaults();
        unset($doc['ranks']);

        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($ownerData))->assertStatus(422);
    }

    public function testFinanceCanViewButNotEditPlan(): void
    {
        [$ownerData, $financeData] = $this->bootOwnerAndFinance();

        $this->getJson('/api/v1/admin/plan', $this->adminHeaders($financeData))->assertOk();

        $doc = IziGoPlanFactory::defaults();
        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($financeData))->assertStatus(403);
    }

    public function testPlanUpdateIsAudited(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        $doc = IziGoPlanFactory::defaults();
        $doc['binary_percent_by_rank'][1] = 6;
        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($ownerData))->assertOk();

        $log = $this->getJson('/api/v1/admin/audit-log', $this->adminHeaders($ownerData))->assertOk();
        $entry = collect($log->json('data.data'))->firstWhere('action', 'plan.update');
        $this->assertNotNull($entry);
        $this->assertSame('plan', $entry['entity_type']);
        $this->assertSame(5, $entry['before']['binary_percent_by_rank']['1']);
        $this->assertSame(6, $entry['after']['binary_percent_by_rank']['1']);
    }

    public function testRoleAssignmentIsAudited(): void
    {
        [$ownerData, $financeData] = $this->bootOwnerAndFinance();
        $targetId = $this->memberByTg(401)->id;

        $this->postJson("/api/v1/admin/members/{$targetId}/role", ['role' => 'support'], $this->adminHeaders($ownerData))
            ->assertOk();

        $log = $this->getJson('/api/v1/admin/audit-log?action=role.assign', $this->adminHeaders($ownerData))->assertOk();
        $entry = collect($log->json('data.data'))->firstWhere('entity_id', $targetId);
        $this->assertNotNull($entry);
        $this->assertSame('role.assign', $entry['action']);
        $this->assertContains('support', $entry['after']['roles']);
    }

    public function testAuditLogOwnerOnly(): void
    {
        [$ownerData, $financeData] = $this->bootOwnerAndFinance();

        $this->getJson('/api/v1/admin/audit-log', $this->adminHeaders($financeData))->assertStatus(403);
    }

    /** Удаление уровня из матрицы реально убирает бонус (не воскресает из дефолтов). */
    public function testRemovingLeaderLevelActuallyRemovesIt(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        $doc = IziGoPlanFactory::defaults();
        unset($doc['leader_percent'][2]); // убираем 2-й уровень лидерского целиком

        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($ownerData))->assertOk();

        // Был 10% (level2/pkg3/rank4) — после удаления должен стать 0, а не вернуться из дефолтов.
        $plan = app(EloquentPlanRepository::class)->load();
        $this->assertSame(0.0, $plan->leaderPercent(2, 3, 4)->value);
    }

    public function testDuplicateRankIdRejected(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        $doc = IziGoPlanFactory::defaults();
        $doc['ranks'][1]['id'] = 1; // два ранга с id=1

        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($ownerData))->assertStatus(422);
    }

    public function testLeaderRefersUnknownPackageRejected(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        $doc = IziGoPlanFactory::defaults();
        $doc['leader_percent'][1][99] = [4 => 10]; // пакета 99 нет

        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($ownerData))->assertStatus(422);
    }

    public function testReferralRefersUnknownPackageSortRejected(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        $doc = IziGoPlanFactory::defaults();
        $doc['referral_percent'][9] = [1 => 10]; // packageSort 9 не существует

        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($ownerData))->assertStatus(422);
    }

    public function testMaxRankDiffZeroRejected(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        $doc = IziGoPlanFactory::defaults();
        $doc['global']['max_rank_diff'] = 0; // 0 отрезал бы лидерские бонусы целиком

        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($ownerData))->assertStatus(422);
    }

    /** Forward-only: смена плана НЕ пересчитывает уже начисленное. */
    public function testForwardOnlyDoesNotRecomputePastAccruals(): void
    {
        [$ownerData] = $this->bootOwnerAndFinance();

        // Спонсор + личник, обе активации → реферальный бонус начислен спонсору.
        [$sponsorData, $sponsorRef] = $this->registerTg(500, name: 'Sponsor');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => 2], $this->tgHeaders($sponsorData))->assertOk();
        [$childData] = $this->registerTg(501, $sponsorRef, 'Child');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => 1], $this->tgHeaders($childData))->assertOk();

        $before = $this->getJson('/api/v1/cabinet/wallet', $this->tgHeaders($sponsorData))->json('data');

        // Owner обнуляет реферальный процент.
        $doc = IziGoPlanFactory::defaults();
        $doc['referral_percent'] = [1 => [1 => 0, 2 => 0], 2 => [1 => 0, 2 => 0], 3 => [1 => 0, 2 => 0]];
        $this->putJson('/api/v1/admin/plan', $doc, $this->adminHeaders($ownerData))->assertOk();

        // Кошелёк спонсора не изменился — прошлое начисление осталось как было.
        $after = $this->getJson('/api/v1/cabinet/wallet', $this->tgHeaders($sponsorData))->json('data');
        $this->assertSame($before, $after);
    }
}
