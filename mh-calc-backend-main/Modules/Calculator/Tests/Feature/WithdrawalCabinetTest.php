<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Заявки на вывод партнёра (Фаза 3): холд доступного баланса при создании,
 * запрет вывода сверх доступного, список своих заявок, доступ по initData.
 */
class WithdrawalCabinetTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private const BRONZE = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** Root зарабатывает referral $9 (активен сам + активный личник). */
    private function earningRoot(int $base): string
    {
        [$rootData, $rootRef] = $this->registerTg($base, name: 'Root');
        [$aData] = $this->registerTg($base + 1, $rootRef, 'A');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData));

        return $rootData;
    }

    public function testCreateWithdrawalHoldsFunds(): void
    {
        $rootData = $this->earningRoot(100);

        $res = $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '5.00', 'payout_details' => 'EQABAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAc3j'], $this->tgHeaders($rootData))->assertOk();
        $this->assertSame('requested', $res->json('data.status'));
        $this->assertSame('5.00', $res->json('data.amount'));

        // Баланс: доступно 9−5=4, в холде 5.
        $wallet = $this->getJson('/api/v1/cabinet/wallet', $this->tgHeaders($rootData))->json('data');
        $this->assertSame('4.00', $wallet['available']);
        $this->assertSame('5.00', $wallet['held']);
    }

    public function testWithdrawalExceedingAvailableIsRejected(): void
    {
        $rootData = $this->earningRoot(200);

        // $20 при доступных $9 → доменная ошибка (404 от guarded).
        $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '20.00', 'payout_details' => 'EQABAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAc3j'], $this->tgHeaders($rootData))->assertStatus(404);

        // Баланс не тронут.
        $wallet = $this->getJson('/api/v1/cabinet/wallet', $this->tgHeaders($rootData))->json('data');
        $this->assertSame('9.00', $wallet['available']);
        $this->assertSame('0.00', $wallet['held']);
    }

    public function testWithdrawalsListedForOwner(): void
    {
        $rootData = $this->earningRoot(300);
        $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '3.00', 'payout_details' => 'EQABAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAc3j'], $this->tgHeaders($rootData))->assertOk();

        $list = $this->getJson('/api/v1/cabinet/withdrawals', $this->tgHeaders($rootData))->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('3.00', $list->json('data.0.amount'));
        $this->assertSame('requested', $list->json('data.0.status'));
    }

    public function testZeroAmountRejected(): void
    {
        $rootData = $this->earningRoot(400);
        $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '0', 'payout_details' => 'EQABAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAc3j'], $this->tgHeaders($rootData))->assertStatus(404);
    }

    public function testWithdrawalRequiresTelegramInitData(): void
    {
        $this->postJson('/api/v1/cabinet/withdrawals', ['amount' => '1.00', 'payout_details' => 'x'],
            ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
    }
}
