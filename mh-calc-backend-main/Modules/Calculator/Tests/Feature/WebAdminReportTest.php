<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Read-разделы веб-админки (блок C): Дашборд (KPI), Финансы (ledger + кошелёк),
 * Операции (платежи/autoship) + RBAC-гейты.
 */
class WebAdminReportTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /**
     * Owner-спонсор + личник: активации дают спонсору реферальный бонус (ledger+кошелёк),
     * owner создаёт заявку на вывод (холд + pending). @return [ownerData, ownerId]
     */
    private function scenarioWithMoney(): array
    {
        [$ownerData, $ownerRef] = $this->registerTg(700, name: 'Owner');
        $this->grantRole(700, 'owner');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => 2], $this->tgHeaders($ownerData));

        [$childData] = $this->registerTg(701, $ownerRef, 'Child');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => 1], $this->tgHeaders($childData));

        // Owner заработал реферал ($9), создаёт заявку на $5 (холд 500).
        $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '5.00', 'payout_details' => 'IBAN'], $this->tgHeaders($ownerData))->assertOk();

        return [$ownerData, $this->memberByTg(700)->id];
    }

    public function testDashboardKpis(): void
    {
        [$ownerData] = $this->scenarioWithMoney();

        $res = $this->getJson('/api/v1/admin/dashboard', $this->adminHeaders($ownerData))->assertOk();

        $this->assertSame(2, $res->json('data.members_total'));
        $this->assertSame(2, $res->json('data.members_active'));
        $this->assertSame(1, $res->json('data.withdrawals_pending'));
        $this->assertSame(500, $res->json('data.withdrawals_pending_amount_cents'));
        $this->assertGreaterThan(0, $res->json('data.company_commission_expense_cents'));
    }

    public function testLedgerListsEntriesFilteredByMember(): void
    {
        [$ownerData, $ownerId] = $this->scenarioWithMoney();

        $res = $this->getJson("/api/v1/admin/ledger?member_id={$ownerId}", $this->adminHeaders($ownerData))->assertOk();

        $this->assertGreaterThan(0, $res->json('data.total'));
        foreach ($res->json('data.data') as $row) {
            $this->assertSame($ownerId, $row['member_id']);
        }
    }

    public function testMemberWalletReturnsBalance(): void
    {
        [$ownerData, $ownerId] = $this->scenarioWithMoney();

        $res = $this->getJson("/api/v1/admin/members/{$ownerId}/wallet", $this->adminHeaders($ownerData))->assertOk();

        // $9 реферал − $5 в холде → доступно 400, холд 500.
        $this->assertSame(400, $res->json('data.available_cents'));
        $this->assertSame(500, $res->json('data.held_cents'));
    }

    public function testPaymentsAndAutoshipReadable(): void
    {
        [$ownerData] = $this->scenarioWithMoney();

        $this->getJson('/api/v1/admin/payments', $this->adminHeaders($ownerData))
            ->assertOk()->assertJsonStructure(['data' => ['data', 'total']]);
        $this->getJson('/api/v1/admin/autoship', $this->adminHeaders($ownerData))
            ->assertOk()->assertJsonStructure(['data' => ['data', 'total']]);
    }

    public function testSupportCannotAccessLedger(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(710, name: 'Owner');
        $this->grantRole(710, 'owner');
        [$supportData] = $this->registerTg(711, $ownerRef, 'Support');
        $this->grantRole(711, 'support');

        // Дашборд support видит, а ledger (owner/finance) — нет.
        $this->getJson('/api/v1/admin/dashboard', $this->adminHeaders($supportData))->assertOk();
        $this->getJson('/api/v1/admin/ledger', $this->adminHeaders($supportData))->assertStatus(403);
    }

    public function testRolelessCannotAccessDashboard(): void
    {
        [$partnerData] = $this->registerTg(712, name: 'Partner');

        $this->getJson('/api/v1/admin/dashboard', $this->adminHeaders($partnerData))->assertStatus(403);
    }

    // --- A1: отчёты/аналитика ---

    public function testReportBalancesTotalsMatchWallets(): void
    {
        [$ownerData] = $this->scenarioWithMoney();

        $res = $this->getJson('/api/v1/admin/reports/balances', $this->adminHeaders($ownerData))->assertOk();

        // Только owner с деньгами: $9 реферал − $5 в холде → доступно 400, холд 500; долга нет.
        $this->assertSame(400, $res->json('data.totals.available_cents'));
        $this->assertSame(500, $res->json('data.totals.held_cents'));
        $this->assertSame(0, $res->json('data.totals.clawback_debt_cents'));
        $this->assertGreaterThanOrEqual(2, count($res->json('data.data')));
    }

    public function testReportUsersListsMembersWithCounts(): void
    {
        [$ownerData] = $this->scenarioWithMoney();

        $res = $this->getJson('/api/v1/admin/reports/users', $this->adminHeaders($ownerData))->assertOk();

        $this->assertSame(2, $res->json('data.counts.total'));
        $this->assertSame(2, $res->json('data.counts.active'));
        $this->assertCount(2, $res->json('data.data'));
    }

    public function testReportUsersFilteredByStatus(): void
    {
        [$ownerData] = $this->scenarioWithMoney();
        // Новый партнёр без активации — статус registered.
        $this->registerTg(713, $this->memberByTg(700)->ref_code, 'Fresh');

        $res = $this->getJson('/api/v1/admin/reports/users?status=registered', $this->adminHeaders($ownerData))->assertOk();
        $this->assertNotEmpty($res->json('data.data'));

        foreach ($res->json('data.data') as $row) {
            $this->assertSame('registered', $row['status']);
        }
    }

    public function testReportSalesReturnsAggregates(): void
    {
        [$ownerData] = $this->scenarioWithMoney();

        $res = $this->getJson('/api/v1/admin/reports/sales', $this->adminHeaders($ownerData))->assertOk();

        $res->assertJsonStructure(['data' => ['orders', 'revenue_cents', 'pv']]);
        $this->assertIsInt($res->json('data.revenue_cents'));
        $this->assertGreaterThanOrEqual(0, $res->json('data.orders'));
    }

    public function testReportBonusExpenseTotalAndTypeBreakdown(): void
    {
        [$ownerData] = $this->scenarioWithMoney();

        $res = $this->getJson('/api/v1/admin/reports/bonus-expense', $this->adminHeaders($ownerData))->assertOk();

        // Реферальный бонус owner'у ($9) → расход компании > 0 и referral в снимке > 0.
        $this->assertGreaterThan(0, $res->json('data.total_expense_cents'));
        $byType = collect($res->json('data.by_type_snapshot'))->keyBy('type');
        $this->assertSame(900, $byType['referral']['amount_cents']);
    }

    public function testSupportCannotAccessFinancialReports(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(720, name: 'Owner');
        $this->grantRole(720, 'owner');
        [$supportData] = $this->registerTg(721, $ownerRef, 'Support');
        $this->grantRole(721, 'support');

        // Пользователи/продажи support видит; балансы/расход (owner,finance) — нет.
        $this->getJson('/api/v1/admin/reports/users', $this->adminHeaders($supportData))->assertOk();
        $this->getJson('/api/v1/admin/reports/sales', $this->adminHeaders($supportData))->assertOk();
        $this->getJson('/api/v1/admin/reports/balances', $this->adminHeaders($supportData))->assertStatus(403);
        $this->getJson('/api/v1/admin/reports/bonus-expense', $this->adminHeaders($supportData))->assertStatus(403);
    }

    public function testRolelessCannotAccessReports(): void
    {
        [$partnerData] = $this->registerTg(722, name: 'Partner');

        $this->getJson('/api/v1/admin/reports/users', $this->adminHeaders($partnerData))->assertStatus(403);
    }

    // --- B1: генеалогия (read-only view) ---

    public function testGenealogyReturnsBinaryTreeFromNetworkTop(): void
    {
        [$ownerData, $ownerId] = $this->scenarioWithMoney();

        $res = $this->getJson('/api/v1/admin/genealogy', $this->adminHeaders($ownerData))->assertOk();

        // Вершина сети (parent_id IS NULL) = owner; ребёнок 701 — в его поддереве.
        $this->assertSame($ownerId, $res->json('data.tree.id'));
        $childIds = collect($res->json('data.tree.children'))->pluck('id');
        $this->assertSame($this->memberByTg(701)->id, $childIds->first());
        $this->assertContains($res->json('data.tree.children.0.position'), ['left', 'right']);
    }

    public function testGenealogyFromGivenRoot(): void
    {
        [$ownerData] = $this->scenarioWithMoney();
        $childId = $this->memberByTg(701)->id;

        $res = $this->getJson("/api/v1/admin/genealogy?root_id={$childId}", $this->adminHeaders($ownerData))->assertOk();

        $this->assertSame($childId, $res->json('data.tree.id'));
        $this->assertSame([], $res->json('data.tree.children'));
    }

    public function testGenealogyAccessibleToSupportNotRoleless(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(730, name: 'Owner');
        $this->grantRole(730, 'owner');
        [$supportData] = $this->registerTg(731, $ownerRef, 'Support');
        $this->grantRole(731, 'support');
        [$partnerData] = $this->registerTg(732, $ownerRef, 'Partner');

        $this->getJson('/api/v1/admin/genealogy', $this->adminHeaders($supportData))->assertOk();
        $this->getJson('/api/v1/admin/genealogy', $this->adminHeaders($partnerData))->assertStatus(403);
    }
}
