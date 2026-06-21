<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\PayoutTransaction;
use Modules\Calculator\Models\WithdrawalRequest;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\Services\Payout\PayoutResult;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * On-chain выплаты USDT (Фаза 4, S7 / US-8): approved → send → paid с tx_hash; неуспех
 * шлюза возвращает холд и отменяет заявку; RBAC только owner/finance.
 * Гоняем FakePayoutGateway (адрес "FAIL" — негативный путь).
 */
class PayoutOnChainTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config(['calculator.payout_gateway' => 'fake']);
    }

    /** Root с балансом $20 создаёт заявку на $5, отдельный finance — апрувер. */
    private function scenario(int $base, string $address): array
    {
        [$rootData, $rootRef] = $this->registerTg($base, name: 'Root');
        [$financeData] = $this->registerTg($base + 1, $rootRef, 'Finance');
        $this->grantRole($base + 1, 'finance');

        $rootId = $this->memberByTg($base)->id;
        DB::transaction(fn () => app(LedgerService::class)->deposit($rootId, 2000, "seed:m{$rootId}"));

        $wd = $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '5.00', 'payout_details' => $address], $this->tgHeaders($rootData))->json('data');

        return [$rootData, $financeData, (int) $wd['id'], $rootId];
    }

    public function testApprovedSendGoesPaidWithTxHash(): void
    {
        [, $financeData, $id, $rootId] = $this->scenario(900, 'EQ_ton_addr');

        $this->postJson("/api/v1/admin/withdrawals/{$id}/approve", [], $this->tgHeaders($financeData))->assertOk();

        $res = $this->postJson("/api/v1/admin/withdrawals/{$id}/send", [], $this->tgHeaders($financeData))->assertOk();
        $res->assertJsonPath('data.status', WithdrawalRequest::STATUS_PAID);
        $this->assertNotEmpty($res->json('data.tx_hash'));

        $tx = PayoutTransaction::where('withdrawal_request_id', $id)->first();
        $this->assertSame(PayoutTransaction::STATUS_CONFIRMED, $tx->status);

        $wallet = MemberWallet::where('member_id', $rootId)->first();
        $this->assertSame(0, $wallet->held_cents);
        $this->assertSame(1500, $wallet->available_cents); // 2000 − 500 выплачено
    }

    public function testFailedSendReturnsHoldAndCancels(): void
    {
        [, $financeData, $id, $rootId] = $this->scenario(910, 'FAIL');

        $this->postJson("/api/v1/admin/withdrawals/{$id}/approve", [], $this->tgHeaders($financeData))->assertOk();
        $this->postJson("/api/v1/admin/withdrawals/{$id}/send", [], $this->tgHeaders($financeData))->assertStatus(400);

        $this->assertSame(WithdrawalRequest::STATUS_CANCELLED, WithdrawalRequest::find($id)->status);
        $this->assertSame(PayoutTransaction::STATUS_FAILED, PayoutTransaction::where('withdrawal_request_id', $id)->first()->status);

        $wallet = MemberWallet::where('member_id', $rootId)->first();
        $this->assertSame(0, $wallet->held_cents);
        $this->assertSame(2000, $wallet->available_cents); // холд возвращён
    }

    public function testCannotSendNonApproved(): void
    {
        [, $financeData, $id] = $this->scenario(920, 'EQ_addr');

        // Заявка в requested (не approved) → 422.
        $this->postJson("/api/v1/admin/withdrawals/{$id}/send", [], $this->tgHeaders($financeData))->assertStatus(422);
    }

    public function testNonFinanceForbidden(): void
    {
        [$rootData, , $id] = $this->scenario(930, 'EQ_addr');

        // Root не финансист и не owner → 403.
        $this->postJson("/api/v1/admin/withdrawals/{$id}/send", [], $this->tgHeaders($rootData))->assertStatus(403);
    }

    public function testBroadcastKeepsHoldUntilPollConfirms(): void
    {
        [, $financeData, $id, $rootId] = $this->scenario(940, 'BROADCAST_addr');

        $this->postJson("/api/v1/admin/withdrawals/{$id}/approve", [], $this->tgHeaders($financeData))->assertOk();
        $this->postJson("/api/v1/admin/withdrawals/{$id}/send", [], $this->tgHeaders($financeData))
            ->assertOk()->assertJsonPath('data.payout_status', PayoutTransaction::STATUS_BROADCAST);

        // На broadcast заявка ещё approved, средства в холде (не выплачены).
        $this->assertSame(WithdrawalRequest::STATUS_APPROVED, WithdrawalRequest::find($id)->status);
        $w = MemberWallet::where('member_id', $rootId)->first();
        $this->assertSame(500, $w->held_cents);

        // Poll подтверждает → заявка paid, холд списан.
        $this->artisan('commerce:payouts-poll')->assertExitCode(0);
        $this->assertSame(WithdrawalRequest::STATUS_PAID, WithdrawalRequest::find($id)->status);
        $this->assertSame(0, MemberWallet::where('member_id', $rootId)->first()->held_cents);
    }

    public function testBroadcastFailedOnPollReturnsHold(): void
    {
        [, $financeData, $id, $rootId] = $this->scenario(945, 'BROADCAST_addr');
        $this->postJson("/api/v1/admin/withdrawals/{$id}/approve", [], $this->tgHeaders($financeData))->assertOk();
        $this->postJson("/api/v1/admin/withdrawals/{$id}/send", [], $this->tgHeaders($financeData))->assertOk();

        // Прямой вызов финализации с провалом сети (poll с gateway.status=failed).
        $txId = PayoutTransaction::where('withdrawal_request_id', $id)->value('id');
        app(\Modules\Calculator\Services\WithdrawalService::class)->reconcilePayout($txId, PayoutResult::FAILED);

        $this->assertSame(WithdrawalRequest::STATUS_CANCELLED, WithdrawalRequest::find($id)->status);
        $w = MemberWallet::where('member_id', $rootId)->first();
        $this->assertSame(0, $w->held_cents);
        $this->assertSame(2000, $w->available_cents); // холд возвращён
    }
}
