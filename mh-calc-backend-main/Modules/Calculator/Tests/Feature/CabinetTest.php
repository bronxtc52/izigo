<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Кабинет партнёра (Telegram Mini App): профиль/реф-ссылка, активация пакета (мок)
 * и доход на живых данных, дерево команды, прогресс рангов, доступ по initData.
 */
class CabinetTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private const BRONZE = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    public function testMeReturnsMemberAndRefLink(): void
    {
        [$initData] = $this->registerTg(10, name: 'Root');

        $res = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($initData))->assertOk();
        $res->assertJsonPath('status', 'success');
        $refCode = $res->json('data.member.ref_code');
        $this->assertNotEmpty($refCode);
        // Реф-ссылка — Telegram deep-link на Mini App с startapp=<ref_code> (не веб-URL).
        $this->assertStringContainsString('t.me/', $res->json('data.ref_link'));
        $this->assertStringContainsString("startapp={$refCode}", $res->json('data.ref_link'));
        $this->assertSame('registered', $res->json('data.member.status'));
        $this->assertSame([], $res->json('data.member.roles'));
    }

    public function testActivationReflectsIncomeOnDashboard(): void
    {
        [$rootData, $rootRef] = $this->registerTg(20, name: 'Root');
        [$aData] = $this->registerTg(21, $rootRef, 'A');

        // Спонсор активен сам + активируется личник → реферал $9 спонсору.
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData))
            ->assertOk()->assertJsonPath('data.member.status', 'active');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData))
            ->assertOk();

        $dash = $this->getJson('/api/v1/cabinet/dashboard', $this->tgHeaders($rootData))->assertOk();
        $this->assertEqualsWithDelta(9.0, (float) $dash->json('data.total'), 0.001);
        $this->assertEqualsWithDelta(9.0, (float) ($dash->json('data.by_type.referral') ?? 0), 0.001);
        $this->assertNotEmpty($dash->json('data.lines'));
        $this->assertSame('referral', $dash->json('data.lines.0.type'));
    }

    public function testTeamTreeContainsDownline(): void
    {
        [$rootData, $rootRef] = $this->registerTg(30, name: 'Root');
        $this->registerTg(31, $rootRef, 'Downline');

        $tree = $this->getJson('/api/v1/cabinet/team-tree', $this->tgHeaders($rootData))->assertOk();
        $this->assertNotEmpty($tree->json('data.children'));
        $this->assertStringContainsString('Downline', $tree->json('data.children.0.name'));
    }

    public function testDashboardIsIsolatedBetweenPartners(): void
    {
        [$rootData, $rootRef] = $this->registerTg(40, name: 'Root');
        [$aData] = $this->registerTg(41, $rootRef, 'A');
        [$bData] = $this->registerTg(42, $rootRef, 'B');

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData));

        // Root заработал реферал; B (не спонсор A) не должен видеть чужой доход.
        $rootDash = $this->getJson('/api/v1/cabinet/dashboard', $this->tgHeaders($rootData))->json('data');
        $bDash = $this->getJson('/api/v1/cabinet/dashboard', $this->tgHeaders($bData))->json('data');

        $this->assertEqualsWithDelta(9.0, (float) $rootDash['total'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $bDash['total'], 0.001);
        $this->assertEmpty($bDash['lines']);
    }

    public function testRankProgressReturnsCurrentAndNext(): void
    {
        [$initData] = $this->registerTg(50, name: 'Root');

        $res = $this->getJson('/api/v1/cabinet/rank-progress', $this->tgHeaders($initData))->assertOk();
        $this->assertArrayHasKey('next', $res->json('data'));
        $this->assertArrayHasKey('progress', $res->json('data'));
    }

    public function testActivateValidatesPackage(): void
    {
        [$initData] = $this->registerTg(60, name: 'Root');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => 999], $this->tgHeaders($initData))
            ->assertStatus(422);
    }

    public function testCabinetRequiresTelegramInitData(): void
    {
        // Без initData (заход вне Telegram) — 401, «Откройте через Telegram».
        $this->getJson('/api/v1/cabinet/me', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
    }
}
