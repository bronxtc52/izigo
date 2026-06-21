<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Approval-флоу финансиста (Фаза 3): очередь заявок, статус-машина
 * (approve/reject/mark-paid/cancel), возврат холда, RBAC (только owner/finance).
 */
class WithdrawalAdminTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private const BRONZE = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /**
     * Root зарабатывает $9 и создаёт заявку на $5; отдельный finance — апрувер.
     *
     * @return array{0:string,1:string,2:int,3:string} [rootData, financeData, withdrawalId, partnerData]
     */
    private function scenario(int $base): array
    {
        [$rootData, $rootRef] = $this->registerTg($base, name: 'Root');
        [$aData] = $this->registerTg($base + 1, $rootRef, 'A');
        [$financeData] = $this->registerTg($base + 2, $rootRef, 'Finance');
        $this->grantRole($base + 2, 'finance');

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData));

        $wd = $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '5.00', 'payout_details' => 'IBAN'], $this->tgHeaders($rootData))->json('data');

        return [$rootData, $financeData, (int) $wd['id'], $aData];
    }

    private function available(string $initData): string
    {
        return $this->getJson('/api/v1/cabinet/wallet', $this->tgHeaders($initData))->json('data.available');
    }

    public function testFinanceSeesQueueAndApproves(): void
    {
        [$rootData, $financeData, $id] = $this->scenario(200);

        $queue = $this->getJson('/api/v1/admin/withdrawals?status=requested', $this->tgHeaders($financeData))->assertOk();
        $this->assertSame($id, $queue->json('data.0.id'));

        $this->postJson("/api/v1/admin/withdrawals/{$id}/approve", [], $this->tgHeaders($financeData))
            ->assertOk()->assertJsonPath('data.status', 'approved');

        // Средства остаются в холде (доступно 4).
        $this->assertSame('4.00', $this->available($rootData));
    }

    public function testRejectReturnsHold(): void
    {
        [$rootData, $financeData, $id] = $this->scenario(210);

        $this->postJson("/api/v1/admin/withdrawals/{$id}/reject", ['reason' => 'нет реквизитов'], $this->tgHeaders($financeData))
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        // Холд возвращён в доступный баланс ($9 снова доступно).
        $this->assertSame('9.00', $this->available($rootData));
    }

    public function testApproveThenMarkPaidConsumesFunds(): void
    {
        [$rootData, $financeData, $id] = $this->scenario(220);

        $this->postJson("/api/v1/admin/withdrawals/{$id}/approve", [], $this->tgHeaders($financeData))->assertOk();
        $this->postJson("/api/v1/admin/withdrawals/{$id}/mark-paid", [], $this->tgHeaders($financeData))
            ->assertOk()->assertJsonPath('data.status', 'paid');

        // Выплачено: held списан, доступно осталось 4 (не возвращается).
        $this->assertSame('4.00', $this->available($rootData));
    }

    public function testApproveThenCancelReturnsHold(): void
    {
        [$rootData, $financeData, $id] = $this->scenario(230);

        $this->postJson("/api/v1/admin/withdrawals/{$id}/approve", [], $this->tgHeaders($financeData))->assertOk();
        $this->postJson("/api/v1/admin/withdrawals/{$id}/cancel", [], $this->tgHeaders($financeData))
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('9.00', $this->available($rootData));
    }

    public function testInvalidTransitionRejected(): void
    {
        [, $financeData, $id] = $this->scenario(240);

        // mark-paid из requested (минуя approve) — недопустимый переход (422).
        $this->postJson("/api/v1/admin/withdrawals/{$id}/mark-paid", [], $this->tgHeaders($financeData))
            ->assertStatus(422);
    }

    public function testPartnerCannotAccessWithdrawalQueue(): void
    {
        [, , , $partnerData] = $this->scenario(250);

        // Партнёр без роли finance/owner — 403.
        $this->getJson('/api/v1/admin/withdrawals', $this->tgHeaders($partnerData))->assertStatus(403);
    }
}
