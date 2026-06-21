<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\KycRecord;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * KYC-intake и пороговый гейт перед выводом (Фаза 4, S8 / US-9): подача Passport,
 * ручной аппрув финансистом, блокировка вывода выше порога без одобренного KYC.
 */
class KycTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config(['calculator.kyc_threshold_cents' => 1000]); // выше $10 нужен KYC
    }

    private function seedBalance(int $tg, int $cents): int
    {
        $id = $this->memberByTg($tg)->id;
        DB::transaction(fn () => app(LedgerService::class)->deposit($id, $cents, "seed:m{$id}"));

        return $id;
    }

    private function withdraw(string $data, string $amount): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => $amount, 'payout_details' => 'EQ_addr'], $this->tgHeaders($data));
    }

    public function testSubmitSetsPendingStatus(): void
    {
        [$data] = $this->registerTg(950, name: 'User');

        $this->postJson('/api/v1/cabinet/kyc/passport',
            ['documents' => ['passport' => 'enc-blob']], $this->tgHeaders($data))
            ->assertOk()->assertJsonPath('data.status', KycRecord::STATUS_PENDING);

        $this->getJson('/api/v1/cabinet/kyc', $this->tgHeaders($data))
            ->assertOk()->assertJsonPath('data.status', KycRecord::STATUS_PENDING);
    }

    public function testUnderThresholdAllowedWithoutKyc(): void
    {
        [$data, $ref] = $this->registerTg(955, name: 'User');
        $this->seedBalance(955, 2000);

        $this->withdraw($data, '5.00')->assertOk(); // 500 ≤ 1000 — без KYC
    }

    public function testAboveThresholdBlockedWithoutKyc(): void
    {
        [$data] = $this->registerTg(960, name: 'User');
        $this->seedBalance(960, 2000);

        // 1500 > 1000 и KYC нет → блокировка (RuntimeException → 404 в кабинете).
        $this->withdraw($data, '15.00')->assertStatus(404);
    }

    public function testApprovalUnblocksAboveThreshold(): void
    {
        [$userData, $userRef] = $this->registerTg(970, name: 'User');
        [$financeData] = $this->registerTg(971, $userRef, 'Finance');
        $this->grantRole(971, 'finance');
        $this->seedBalance(970, 2000);

        // Подал KYC.
        $kycId = $this->postJson('/api/v1/cabinet/kyc/passport',
            ['documents' => ['passport' => 'enc']], $this->tgHeaders($userData))->json('data.id');
        // До аппрува — заблокировано.
        $this->withdraw($userData, '15.00')->assertStatus(404);

        // Финансист одобряет.
        $this->patchJson("/api/v1/admin/kyc/{$kycId}",
            ['approve' => true], $this->tgHeaders($financeData))
            ->assertOk()->assertJsonPath('data.status', KycRecord::STATUS_APPROVED);

        // Теперь вывод проходит.
        $this->withdraw($userData, '15.00')->assertOk();
    }

    public function testNonFinanceCannotReview(): void
    {
        [$userData] = $this->registerTg(980, name: 'User');
        $kycId = $this->postJson('/api/v1/cabinet/kyc/passport',
            ['documents' => ['passport' => 'enc']], $this->tgHeaders($userData))->json('data.id');

        // User не финансист/owner → 403.
        $this->patchJson("/api/v1/admin/kyc/{$kycId}",
            ['approve' => true], $this->tgHeaders($userData))->assertStatus(403);
    }
}
