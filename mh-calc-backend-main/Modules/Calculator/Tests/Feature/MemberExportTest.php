<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\KycRecord;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\WithdrawalRequest;
use Modules\Calculator\Services\Pii\ExportService;
use Modules\Calculator\Services\Pii\PiiService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * C5 (Block C): экспорт участника + PII маска/reveal/аудит. Покрывает:
 *  - маска по умолчанию (owner/finance/support видят PII замаскированным);
 *  - reveal: owner → реальные значения + запись pii.reveal в аудит;
 *  - reveal RBAC: finance/support/plain → 403; без auth → 401 (deny-by-default на бэкенде);
 *  - export: owner masked=false = полные данные + member.export; finance/support принудит. маска;
 *  - export RBAC: plain → 403; без auth → 401;
 *  - CSV anti-injection: ячейка `=cmd` экранируется апострофом;
 *  - аудит НЕ содержит сырых значений PII.
 */
class MemberExportTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    // Синтетические PII-значения (не реальные данные).
    private const TG_USERNAME = '@alice_test';
    private const TON_ADDR = 'EQ1234567890abcdefXYZ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** Owner + finance + support + plain; возвращает их initData. */
    private function bootRoles(): array
    {
        [$ownerData, $ownerRef] = $this->registerTg(800, name: 'Owner');
        $this->grantRole(800, 'owner');
        [$financeData] = $this->registerTg(801, $ownerRef, 'Finance');
        $this->grantRole(801, 'finance');
        [$supportData] = $this->registerTg(802, $ownerRef, 'Support');
        $this->grantRole(802, 'support');
        [$plainData] = $this->registerTg(803, $ownerRef, 'Plain');

        return compact('ownerData', 'financeData', 'supportData', 'plainData');
    }

    /** Целевой участник с заполненными PII (username + payout + kyc). Возвращает id. */
    private function targetMemberWithPii(string $ownerRef): int
    {
        [$data] = $this->registerTg(900, $ownerRef, 'Target');
        $member = $this->memberByTg(900);
        $member->telegram_username = self::TG_USERNAME;
        $member->save();

        WithdrawalRequest::query()->create([
            'member_id' => $member->id,
            'amount_cents' => 1000,
            'payout_details' => self::TON_ADDR,
            'status' => WithdrawalRequest::STATUS_REQUESTED,
            'requested_at' => now(),
        ]);
        KycRecord::query()->create([
            'member_id' => $member->id,
            'source' => 'test',
            'review_status' => KycRecord::STATUS_APPROVED,
        ]);

        return $member->id;
    }

    // --- маска по умолчанию ---

    public function testSummaryMasksPiiForOwner(): void
    {
        $r = $this->bootRoles();
        $id = $this->targetMemberWithPii($this->memberByTg(800)->ref_code);

        $res = $this->getJson("/api/v1/admin/members/{$id}/pii", $this->adminHeaders($r['ownerData']))->assertOk();
        $data = $res->json('data');

        $this->assertSame('@ali***', $data['telegram_username']);
        $this->assertStringContainsString('***', $data['payout_details']);
        $this->assertStringNotContainsString('abcdef', $data['payout_details']);
        $this->assertSame('***', $data['kyc_status']);
    }

    public function testSummaryMaskedForFinanceAndSupport(): void
    {
        $r = $this->bootRoles();
        $id = $this->targetMemberWithPii($this->memberByTg(800)->ref_code);

        foreach (['financeData', 'supportData'] as $role) {
            $data = $this->getJson("/api/v1/admin/members/{$id}/pii", $this->adminHeaders($r[$role]))
                ->assertOk()->json('data');
            $this->assertSame('@ali***', $data['telegram_username']);
            $this->assertStringNotContainsString(self::TON_ADDR, (string) $data['payout_details']);
        }
    }

    // --- reveal: owner → реальные значения + аудит ---

    public function testRevealReturnsRealValuesForOwnerAndAudits(): void
    {
        $r = $this->bootRoles();
        $id = $this->targetMemberWithPii($this->memberByTg(800)->ref_code);

        $res = $this->postJson("/api/v1/admin/members/{$id}/pii/reveal", [], $this->adminHeaders($r['ownerData']))->assertOk();
        $data = $res->json('data');

        $this->assertSame(self::TG_USERNAME, $data['telegram_username']);
        $this->assertSame(self::TON_ADDR, $data['payout_details']);
        $this->assertSame(KycRecord::STATUS_APPROVED, $data['kyc_status']);

        $log = $this->getJson('/api/v1/admin/audit-log', $this->adminHeaders($r['ownerData']))->assertOk();
        $entry = collect($log->json('data.data'))->firstWhere('action', 'pii.reveal');
        $this->assertNotNull($entry);
        $this->assertSame('member', $entry['entity_type']);
        $this->assertSame($id, $entry['entity_id']);
        $this->assertContains('telegram_username', $entry['after']['fields']);
    }

    // --- reveal RBAC negative-cases ---

    public function testRevealForbiddenForFinanceSupportPlain(): void
    {
        $r = $this->bootRoles();
        $id = $this->targetMemberWithPii($this->memberByTg(800)->ref_code);

        foreach (['financeData', 'supportData', 'plainData'] as $role) {
            $this->postJson("/api/v1/admin/members/{$id}/pii/reveal", [], $this->adminHeaders($r[$role]))
                ->assertStatus(403);
        }
    }

    public function testRevealUnauthenticatedRejected(): void
    {
        $r = $this->bootRoles();
        $id = $this->targetMemberWithPii($this->memberByTg(800)->ref_code);

        $this->postJson("/api/v1/admin/members/{$id}/pii/reveal", [], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    // --- export ---

    public function testOwnerFullExportJsonReturnsRealValuesAndAudits(): void
    {
        $r = $this->bootRoles();
        $id = $this->targetMemberWithPii($this->memberByTg(800)->ref_code);

        $res = $this->getJson("/api/v1/admin/members/{$id}/export?format=json&masked=0", $this->adminHeaders($r['ownerData']))->assertOk();
        $data = $res->json('data');

        $this->assertSame(self::TG_USERNAME, $data['telegram_username']);
        $this->assertSame(self::TON_ADDR, $data['payout_details']);

        $log = $this->getJson('/api/v1/admin/audit-log', $this->adminHeaders($r['ownerData']))->assertOk();
        $entry = collect($log->json('data.data'))->firstWhere('action', 'member.export');
        $this->assertNotNull($entry);
        $this->assertSame('json', $entry['after']['format']);
        $this->assertFalse($entry['after']['masked']);
    }

    public function testFinanceExportIsForcedMaskedEvenWhenRequestingFull(): void
    {
        $r = $this->bootRoles();
        $id = $this->targetMemberWithPii($this->memberByTg(800)->ref_code);

        // Finance просит полный (masked=0), но бэкенд принудительно маскирует.
        $res = $this->getJson("/api/v1/admin/members/{$id}/export?format=json&masked=0", $this->adminHeaders($r['financeData']))->assertOk();
        $data = $res->json('data');

        $this->assertNotSame(self::TG_USERNAME, $data['telegram_username']);
        $this->assertSame('@ali***', $data['telegram_username']);
        $this->assertStringNotContainsString(self::TON_ADDR, (string) $data['payout_details']);

        $log = $this->getJson('/api/v1/admin/audit-log', $this->adminHeaders($r['ownerData']))->assertOk();
        $entry = collect($log->json('data.data'))->firstWhere('action', 'member.export');
        $this->assertTrue($entry['after']['masked']); // зафиксировано как masked, несмотря на masked=0
    }

    public function testExportForbiddenForPlainAndUnauthenticated(): void
    {
        $r = $this->bootRoles();
        $id = $this->targetMemberWithPii($this->memberByTg(800)->ref_code);

        $this->getJson("/api/v1/admin/members/{$id}/export?format=json", $this->adminHeaders($r['plainData']))
            ->assertStatus(403);
        $this->getJson("/api/v1/admin/members/{$id}/export?format=json", ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testSummaryAndRevealUnauthenticated(): void
    {
        $r = $this->bootRoles();
        $id = $this->targetMemberWithPii($this->memberByTg(800)->ref_code);

        $this->getJson("/api/v1/admin/members/{$id}/pii", ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    // --- CSV anti-injection ---

    public function testCsvExportEscapesFormulaInjection(): void
    {
        $r = $this->bootRoles();
        [$data] = $this->registerTg(910, $this->memberByTg(800)->ref_code, '=cmd|/c calc');
        $evil = $this->memberByTg(910);

        $res = $this->get("/api/v1/admin/members/{$evil->id}/export?format=csv", $this->adminHeaders($r['ownerData']))
            ->assertOk();
        $csv = $res->getContent();

        // name начиналось с '=' → должно быть префиксовано апострофом, без сырого ведущего '='.
        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertStringNotContainsString(',=cmd', $csv);
        $this->assertStringNotContainsString("\n=cmd", $csv);
    }

    public function testCsvCellEscapesAllFormulaTriggers(): void
    {
        $svc = app(ExportService::class);
        $ref = new \ReflectionMethod($svc, 'csvCell');
        $ref->setAccessible(true);

        foreach (['=1+1', '+1', '-1', '@SUM(A1)', ' =cmd', "\t=cmd"] as $payload) {
            $out = $ref->invoke($svc, $payload);
            $this->assertStringStartsWith("'", $out, "payload [{$payload}] must be prefixed");
        }
    }

    // --- аудит не содержит сырых значений PII ---

    public function testAuditDoesNotLeakRawPiiValues(): void
    {
        $r = $this->bootRoles();
        $id = $this->targetMemberWithPii($this->memberByTg(800)->ref_code);

        $this->postJson("/api/v1/admin/members/{$id}/pii/reveal", [], $this->adminHeaders($r['ownerData']))->assertOk();
        $this->getJson("/api/v1/admin/members/{$id}/export?format=json&masked=0", $this->adminHeaders($r['ownerData']))->assertOk();

        $log = $this->getJson('/api/v1/admin/audit-log', $this->adminHeaders($r['ownerData']))->assertOk();
        $raw = json_encode($log->json('data.data'));

        $this->assertStringNotContainsString(self::TON_ADDR, $raw);
        $this->assertStringNotContainsString(self::TG_USERNAME, $raw);
    }

    // --- unit: маска ---

    public function testPiiServiceMaskFormats(): void
    {
        $pii = new PiiService();
        $this->assertSame('@ali***', $pii->mask('@alice', PiiService::TYPE_USERNAME));
        $this->assertSame('@bob***', $pii->mask('bob', PiiService::TYPE_USERNAME));
        $this->assertSame('EQ...***...XYZ', $pii->mask('EQ1234567890XYZ', PiiService::TYPE_PAYOUT));
        $this->assertSame('***', $pii->mask('approved', PiiService::TYPE_KYC));
        $this->assertNull($pii->mask(null, PiiService::TYPE_USERNAME));
    }
}
