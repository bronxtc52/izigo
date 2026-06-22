<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\MemberAgreementAcceptance;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * B3: пользовательское соглашение и онбординг-акцепт. Текст/версия — в plan_settings,
 * акцепт — в member_agreement_acceptances; рост версии требует повторного акцепта.
 */
class AgreementTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    public function testMemberSeesUnacceptedAgreement(): void
    {
        [$data] = $this->registerTg(400, name: 'P');

        $res = $this->getJson('/api/v1/cabinet/agreement', $this->tgHeaders($data))->assertOk();

        $this->assertSame(1, $res->json('data.version'));
        $this->assertFalse($res->json('data.accepted'));
        $this->assertNull($res->json('data.accepted_version'));
    }

    public function testAcceptIsIdempotent(): void
    {
        [$data] = $this->registerTg(401, name: 'P');

        $this->postJson('/api/v1/cabinet/agreement/accept', [], $this->tgHeaders($data))->assertOk();
        $res = $this->postJson('/api/v1/cabinet/agreement/accept', [], $this->tgHeaders($data))->assertOk();

        $this->assertTrue($res->json('data.accepted'));
        $this->assertSame(1, $res->json('data.accepted_version'));
        // Повторный accept не задвоил строку.
        $memberId = $this->memberByTg(401)->id;
        $this->assertSame(1, MemberAgreementAcceptance::query()->where('member_id', $memberId)->count());
    }

    public function testNewVersionRequiresReAccept(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(410, name: 'Owner');
        $this->grantRole(410, 'owner');
        [$pData] = $this->registerTg(411, $ownerRef, 'P');

        // Партнёр принял v1.
        $this->postJson('/api/v1/cabinet/agreement/accept', [], $this->tgHeaders($pData))->assertOk();
        $this->assertTrue($this->getJson('/api/v1/cabinet/agreement', $this->tgHeaders($pData))->json('data.accepted'));

        // Owner обновил текст → v2.
        $upd = $this->putJson('/api/v1/admin/agreement', ['text' => 'Новая редакция'], $this->adminHeaders($ownerData))->assertOk();
        $this->assertSame(2, $upd->json('data.version'));

        // Теперь партнёр должен принять заново.
        $st = $this->getJson('/api/v1/cabinet/agreement', $this->tgHeaders($pData))->assertOk();
        $this->assertFalse($st->json('data.accepted'));
        $this->assertSame(2, $st->json('data.version'));
        $this->assertSame(1, $st->json('data.accepted_version'));
    }

    public function testAdminSummaryCountsAcceptances(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(420, name: 'Owner');
        $this->grantRole(420, 'owner');
        [$pData] = $this->registerTg(421, $ownerRef, 'P');
        $this->postJson('/api/v1/cabinet/agreement/accept', [], $this->tgHeaders($pData))->assertOk();

        $res = $this->getJson('/api/v1/admin/agreement', $this->adminHeaders($ownerData))->assertOk();

        $this->assertSame(1, $res->json('data.version'));
        $this->assertSame(1, $res->json('data.accepted_current_count'));
        $this->assertSame(2, $res->json('data.members_total'));
    }

    public function testOnlyOwnerCanUpdateAgreement(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(430, name: 'Owner');
        $this->grantRole(430, 'owner');
        [$supportData] = $this->registerTg(431, $ownerRef, 'S');
        $this->grantRole(431, 'support');

        // support видит текст, но не правит.
        $this->getJson('/api/v1/admin/agreement', $this->adminHeaders($supportData))->assertOk();
        $this->putJson('/api/v1/admin/agreement', ['text' => 'x'], $this->adminHeaders($supportData))->assertStatus(403);
    }

    public function testAgreementRequiresInitData(): void
    {
        $this->getJson('/api/v1/cabinet/agreement', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
    }
}
