<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * B7 (P1-hardening): серверная валидация TON-адреса при создании заявки на вывод.
 * Невалидный/тестнет-адрес — 422 с полем payout_details (раньше бэк принимал любую
 * строку, ошибка всплывала только при ручной выплате).
 */
class WithdrawalValidationTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private const VALID = 'EQABAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAc3j';
    private const TESTNET = 'kQABAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAXZp';
    private const BAD_CRC = 'EQABAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAc3A';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    private function fundedMember(int $tg): string
    {
        [$data] = $this->registerTg($tg, name: "U{$tg}");
        $id = $this->memberByTg($tg)->id;
        DB::transaction(fn () => app(LedgerService::class)->deposit($id, 2000, "seed:m{$id}"));

        return $data;
    }

    public function testValidAddressAccepted(): void
    {
        $data = $this->fundedMember(6000);

        $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '5.00', 'payout_details' => self::VALID], $this->tgHeaders($data))
            ->assertOk()
            ->assertJsonPath('data.status', 'requested');
    }

    public function testBrokenChecksumRejected(): void
    {
        $data = $this->fundedMember(6010);

        $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '5.00', 'payout_details' => self::BAD_CRC], $this->tgHeaders($data))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payout_details']);
    }

    public function testTestnetAddressRejected(): void
    {
        $data = $this->fundedMember(6020);

        $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '5.00', 'payout_details' => self::TESTNET], $this->tgHeaders($data))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payout_details']);
    }

    public function testGarbageRejectedAndBalanceUntouched(): void
    {
        $data = $this->fundedMember(6030);

        $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '5.00', 'payout_details' => 'IBAN DE1234'], $this->tgHeaders($data))
            ->assertStatus(422);

        // Холд не создан, баланс цел.
        $wallet = $this->getJson('/api/v1/cabinet/wallet', $this->tgHeaders($data))->json('data');
        $this->assertSame('20.00', $wallet['available']);
    }
}
