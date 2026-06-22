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
}
