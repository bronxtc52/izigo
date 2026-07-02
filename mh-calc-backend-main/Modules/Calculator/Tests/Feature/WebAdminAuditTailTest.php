<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Аудит «хвоста» админ-мутаций (best-effort): создание продукта, одобрение выплаты,
 * ревью KYC попадают в admin_audit_log с правильным action/entity и атрибуцией актора.
 */
class WebAdminAuditTailTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** @return array{0:string,1:string} [ownerData, ownerRef] */
    private function owner(int $tg): array
    {
        [$data, $ref] = $this->registerTg($tg, name: 'Owner');
        $this->grantRole($tg, 'owner');

        return [$data, $ref];
    }

    private function auditEntry(string $ownerData, string $action, int $entityId): ?array
    {
        $log = $this->getJson("/api/v1/admin/audit-log?action={$action}", $this->adminHeaders($ownerData))->assertOk();

        return collect($log->json('data.data'))->firstWhere('entity_id', $entityId);
    }

    public function testProductCreateAudited(): void
    {
        [$ownerData] = $this->owner(600);

        $product = $this->postJson('/api/v1/admin/products', [
            'name' => 'Курс',
            'price_usdt_cents' => 5000,
            'package_id' => 1,
            'sku' => 'AUDIT-SKU-1',
        ], $this->adminHeaders($ownerData))->assertOk()->json('data');

        $entry = $this->auditEntry($ownerData, 'product.create', (int) $product['id']);
        $this->assertNotNull($entry);
        $this->assertSame('product', $entry['entity_type']);
        $this->assertSame($this->memberByTg(600)->id, $entry['actor_member_id']);
    }

    public function testWithdrawalApproveAudited(): void
    {
        [$ownerData, $ownerRef] = $this->owner(610);
        // Партнёр зарабатывает и подаёт заявку; финансист одобряет.
        [$financeData] = $this->registerTg(611, $ownerRef, 'Finance');
        $this->grantRole(611, 'finance');
        [$aData] = $this->registerTg(612, $ownerRef, 'A');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => 1], $this->tgHeaders($ownerData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => 1], $this->tgHeaders($aData));

        $wid = (int) $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '5.00', 'payout_details' => 'EQABAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAc3j'], $this->tgHeaders($ownerData))->json('data.id');

        $this->postJson("/api/v1/admin/withdrawals/{$wid}/approve", [], $this->adminHeaders($financeData))->assertOk();

        $entry = $this->auditEntry($ownerData, 'withdrawal.approve', $wid);
        $this->assertNotNull($entry);
        $this->assertSame('withdrawal', $entry['entity_type']);
        $this->assertSame($this->memberByTg(611)->id, $entry['actor_member_id']);
    }

    public function testKycReviewAudited(): void
    {
        [$ownerData, $ownerRef] = $this->owner(620);
        [$financeData] = $this->registerTg(621, $ownerRef, 'Finance');
        $this->grantRole(621, 'finance');
        [$userData] = $this->registerTg(622, $ownerRef, 'User');

        $kycId = (int) $this->postJson('/api/v1/cabinet/kyc/passport',
            ['documents' => ['passport' => 'enc']], $this->tgHeaders($userData))->json('data.id');

        $this->patchJson("/api/v1/admin/kyc/{$kycId}", ['approve' => true], $this->adminHeaders($financeData))->assertOk();

        $entry = $this->auditEntry($ownerData, 'kyc.approve', $kycId);
        $this->assertNotNull($entry);
        $this->assertSame('kyc', $entry['entity_type']);
    }
}
