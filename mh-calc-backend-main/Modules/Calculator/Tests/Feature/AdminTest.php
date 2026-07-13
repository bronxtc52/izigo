<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\KycRecord;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\WithdrawalRequest;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Админ-портал + RBAC (Telegram-идентичность): гейты ролей, список/поиск участников,
 * назначение ролей, настройка плана, охват лидера (видит только своё поддерево).
 */
class AdminTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    public function testPartnerCannotAccessAdmin(): void
    {
        [$initData] = $this->registerTg(100, name: 'Partner');
        $this->getJson('/api/v1/admin/members', $this->adminHeaders($initData))->assertStatus(403);
    }

    public function testOwnerListsAndSearchesMembers(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(110, name: 'Owner');
        $this->grantRole(110, 'owner');
        $this->registerTg(111, $ownerRef, 'Alice');

        $list = $this->getJson('/api/v1/admin/members', $this->adminHeaders($ownerData))->assertOk();
        $this->assertGreaterThanOrEqual(2, $list->json('data.total'));

        $found = $this->getJson('/api/v1/admin/members?search=Alice', $this->adminHeaders($ownerData))->assertOk();
        $this->assertSame(1, $found->json('data.total'));
    }

    public function testOwnerAssignsRoleAndGrantsAccess(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(120, name: 'Owner');
        $this->grantRole(120, 'owner');
        [$supportData] = $this->registerTg(121, $ownerRef, 'Support');
        $supportMemberId = $this->memberByTg(121)->id;

        // До назначения роли — нет доступа.
        $this->getJson('/api/v1/admin/members', $this->adminHeaders($supportData))->assertStatus(403);

        // Owner назначает роль support.
        $this->postJson("/api/v1/admin/members/{$supportMemberId}/role", ['role' => 'support'], $this->adminHeaders($ownerData))
            ->assertOk()->assertJsonPath('data.roles.0', 'support');

        // Теперь support видит участников, но не может назначать роли.
        $this->getJson('/api/v1/admin/members', $this->adminHeaders($supportData))->assertOk();
        $this->postJson("/api/v1/admin/members/{$supportMemberId}/role", ['role' => 'owner'], $this->adminHeaders($supportData))
            ->assertStatus(403);
    }

    public function testPlanSettingsEditableByOwnerOnly(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(130, name: 'Owner');
        $this->grantRole(130, 'owner');
        [$financeData] = $this->registerTg(131, $ownerRef, 'Finance');
        $this->grantRole(131, 'finance');

        // Owner меняет режим размещения.
        $this->putJson('/api/v1/admin/plan-settings', ['placement_mode' => 'manual'], $this->adminHeaders($ownerData))
            ->assertOk()->assertJsonPath('data.placement_mode', 'manual');

        // Finance видит, но не может менять.
        $this->getJson('/api/v1/admin/plan-settings', $this->adminHeaders($financeData))
            ->assertOk()->assertJsonPath('data.placement_mode', 'manual');
        $this->putJson('/api/v1/admin/plan-settings', ['placement_mode' => 'auto'], $this->adminHeaders($financeData))
            ->assertStatus(403);
    }

    public function testLeaderSeesOnlyOwnSubtree(): void
    {
        [$ownerData, $rootRef] = $this->registerTg(140, name: 'RootOwner');
        $this->grantRole(140, 'owner');

        [$leaderData, $leaderRef] = $this->registerTg(141, $rootRef, 'Leader');   // под root (левая нога)
        $this->registerTg(142, $leaderRef, 'Downline');                           // под лидером
        $this->registerTg(143, $rootRef, 'Other');                                // под root (правая нога), не под лидером

        $this->grantRole(141, 'leader', $this->memberByTg(141)->id);

        $list = $this->getJson('/api/v1/admin/members', $this->adminHeaders($leaderData))->assertOk();
        $names = collect($list->json('data.data'))->pluck('name')->all();

        $this->assertContains('Leader', $names);
        $this->assertContains('Downline', $names);
        $this->assertNotContains('RootOwner', $names);
        $this->assertNotContains('Other', $names);
    }

    public function testLeaderScopeExcludesSpilloverStranger(): void
    {
        // Охват лидера = спонсорская линия, НЕ placement. Чужой партнёр, заспилловеренный
        // под лидера по дереву размещения, но приглашённый владельцем, виден НЕ должен.
        [, $rootRef] = $this->registerTg(150, name: 'SRoot');
        $this->grantRole(150, 'owner');

        [$leaderData, $leaderRef] = $this->registerTg(151, $rootRef, 'SLeader');  // R.left
        $this->registerTg(152, $rootRef, 'SMid');                                 // R.right (заполняет ноги R)
        $this->registerTg(153, $rootRef, 'SStranger');                            // спилловер под лидера, спонсор — R
        $this->registerTg(154, $leaderRef, 'SDownline');                          // личник лидера (sponsor = L)

        $this->grantRole(151, 'leader', $this->memberByTg(151)->id);

        $names = collect(
            $this->getJson('/api/v1/admin/members', $this->adminHeaders($leaderData))->assertOk()->json('data.data')
        )->pluck('name')->all();

        $this->assertContains('SLeader', $names);
        $this->assertContains('SDownline', $names);          // личник лидера — виден
        $this->assertNotContains('SStranger', $names);       // чужой спилловер — НЕ виден
        $this->assertNotContains('SMid', $names);
    }

    public function testNonOwnerSeesMaskedPiiInMemberCard(): void
    {
        // G1/C5: карточка участника не должна обходить маску PII. finance/support/leader
        // видят telegram_username/ref_code замаскированными (та же маска, что на /pii, /export).
        [, $ownerRef] = $this->registerTg(160, name: 'Owner');
        $this->grantRole(160, 'owner');
        [$supportData] = $this->registerTg(161, $ownerRef, 'Support');
        $this->grantRole(161, 'support');
        $this->registerTg(162, $ownerRef, 'Alice');           // telegram_username = u162
        $aliceId = $this->memberByTg(162)->id;

        $card = $this->getJson("/api/v1/admin/members/{$aliceId}", $this->adminHeaders($supportData))->assertOk();
        $this->assertSame('@u16***', $card->json('data.member.telegram_username'));
        $this->assertSame('***', $card->json('data.member.ref_code'));
    }

    public function testOwnerSeesFullPiiInMemberCard(): void
    {
        // Owner видит PII полностью (reveal-путь без обхода — это его право).
        [$ownerData, $ownerRef] = $this->registerTg(170, name: 'Owner');
        $this->grantRole(170, 'owner');
        $this->registerTg(171, $ownerRef, 'Bob');             // telegram_username = u171
        $bob = $this->memberByTg(171);

        $card = $this->getJson("/api/v1/admin/members/{$bob->id}", $this->adminHeaders($ownerData))->assertOk();
        $this->assertSame('u171', $card->json('data.member.telegram_username'));
        $this->assertSame($bob->ref_code, $card->json('data.member.ref_code'));
    }

    // --- C1: маска payout_details (TON-адрес) + kyc_status в ОБЫЧНОЙ карточке ---
    // Синтетические PII-значения (не реальные данные).
    private const C1_TON_ADDR = 'EQ1234567890abcdefXYZ';

    /** Заполнить у участника PII, живущие вне Member: payout (заявка) + kyc (запись). */
    private function seedMemberPii(Member $member): void
    {
        WithdrawalRequest::query()->create([
            'member_id' => $member->id,
            'amount_cents' => 1000,
            'payout_details' => self::C1_TON_ADDR,
            'status' => WithdrawalRequest::STATUS_REQUESTED,
            'requested_at' => now(),
        ]);
        KycRecord::query()->create([
            'member_id' => $member->id,
            'source' => 'test',
            'review_status' => KycRecord::STATUS_APPROVED,
        ]);
    }

    public function testNonOwnerSeesMaskedPayoutAndKycInMemberCard(): void
    {
        // C1: карточка участника маскирует payout_details/kyc_status для НЕ-owner
        // (finance/support/leader). Значения берутся из заявки вывода и KYC-записи через
        // общий коллектор ExportService — та же маска, что на /pii и /export.
        [, $ownerRef] = $this->registerTg(180, name: 'Owner');
        $this->grantRole(180, 'owner');

        [$financeData] = $this->registerTg(181, $ownerRef, 'Finance');
        $this->grantRole(181, 'finance');
        [$supportData] = $this->registerTg(182, $ownerRef, 'Support');
        $this->grantRole(182, 'support');
        [$leaderData, $leaderRef] = $this->registerTg(183, $ownerRef, 'Leader');
        $this->grantRole(183, 'leader', $this->memberByTg(183)->id);

        // Цель под лидером (в его поддереве) — чтобы лидер тоже мог её открыть.
        $this->registerTg(184, $leaderRef, 'Target');
        $target = $this->memberByTg(184);
        $this->seedMemberPii($target);

        foreach ([$financeData, $supportData, $leaderData] as $viewerData) {
            $res = $this->getJson("/api/v1/admin/members/{$target->id}", $this->adminHeaders($viewerData))->assertOk();
            $member = $res->json('data.member');

            // payout_details замаскирован: содержит ***, но не сырой TON-адрес.
            $this->assertStringContainsString('***', (string) $member['payout_details']);
            $this->assertStringNotContainsString('abcdef', (string) $member['payout_details']);
            $this->assertSame('***', $member['kyc_status']);

            // Ни при каком ключе сырые значения не утекают в тело ответа.
            $raw = $res->getContent();
            $this->assertStringNotContainsString(self::C1_TON_ADDR, $raw);
            $this->assertStringNotContainsString('approved', $raw);
        }
    }

    public function testOwnerSeesRawPayoutAndKycInMemberCard(): void
    {
        // C1: owner видит payout_details/kyc_status сырыми (его право, без reveal).
        [$ownerData, $ownerRef] = $this->registerTg(190, name: 'Owner');
        $this->grantRole(190, 'owner');
        $this->registerTg(191, $ownerRef, 'Target');
        $target = $this->memberByTg(191);
        $this->seedMemberPii($target);

        $member = $this->getJson("/api/v1/admin/members/{$target->id}", $this->adminHeaders($ownerData))
            ->assertOk()->json('data.member');

        $this->assertSame(self::C1_TON_ADDR, $member['payout_details']);
        $this->assertSame(KycRecord::STATUS_APPROVED, $member['kyc_status']);
    }

    public function testMemberCardPiiMaskingIndependentOfC5Flag(): void
    {
        // C1: маскирование обычной карточки живёт в сервисе, НЕ за feature.flag c5_pii_export.
        // Даже при c5 ON поведение то же: не-owner → маска, owner → raw (флаг гейтит только
        // выделенные /pii,/reveal,/export, а не обычную выдачу /members/{id}).
        $this->enableFeatureFlags('c5_pii_export');

        [$ownerData, $ownerRef] = $this->registerTg(200, name: 'Owner');
        $this->grantRole(200, 'owner');
        [$financeData] = $this->registerTg(201, $ownerRef, 'Finance');
        $this->grantRole(201, 'finance');
        $this->registerTg(202, $ownerRef, 'Target');
        $target = $this->memberByTg(202);
        $this->seedMemberPii($target);

        $masked = $this->getJson("/api/v1/admin/members/{$target->id}", $this->adminHeaders($financeData))
            ->assertOk()->json('data.member');
        $this->assertStringNotContainsString('abcdef', (string) $masked['payout_details']);
        $this->assertSame('***', $masked['kyc_status']);

        $raw = $this->getJson("/api/v1/admin/members/{$target->id}", $this->adminHeaders($ownerData))
            ->assertOk()->json('data.member');
        $this->assertSame(self::C1_TON_ADDR, $raw['payout_details']);
        $this->assertSame(KycRecord::STATUS_APPROVED, $raw['kyc_status']);
    }

    public function testMemberListDoesNotExposeRawPii(): void
    {
        // C1-регресс: список участников PII-free — rowOf не отдаёт telegram_username/ref_code/
        // payout_details не-owner'у (список используется как таблица, обход маски недопустим).
        [$ownerData, $ownerRef] = $this->registerTg(210, name: 'Owner');
        $this->grantRole(210, 'owner');
        [$supportData] = $this->registerTg(211, $ownerRef, 'Support');
        $this->grantRole(211, 'support');
        $this->registerTg(212, $ownerRef, 'Target');
        $target = $this->memberByTg(212);
        $this->seedMemberPii($target);

        foreach ([$ownerData, $supportData] as $viewerData) {
            $rows = $this->getJson('/api/v1/admin/members', $this->adminHeaders($viewerData))
                ->assertOk()->json('data.data');
            foreach ($rows as $row) {
                $this->assertArrayNotHasKey('telegram_username', $row);
                $this->assertArrayNotHasKey('ref_code', $row);
                $this->assertArrayNotHasKey('payout_details', $row);
                $this->assertArrayNotHasKey('kyc_status', $row);
            }
            // Сырой TON-адрес не встречается в теле списка ни в каком виде.
            $body = $this->getJson('/api/v1/admin/members', $this->adminHeaders($viewerData))->getContent();
            $this->assertStringNotContainsString(self::C1_TON_ADDR, $body);
        }
    }
}
