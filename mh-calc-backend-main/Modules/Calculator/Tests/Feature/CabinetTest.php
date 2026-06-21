<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Calculator\Models\CalculatorUser;
use Modules\Calculator\Models\CalculatorUserToken;
use Tests\TestCase;

/**
 * Кабинет партнёра: профиль/реф-ссылка, активация пакета (мок) и доход на живых
 * данных, дерево команды, прогресс рангов, доступ.
 */
class CabinetTest extends TestCase
{
    use RefreshDatabase;

    private const BRONZE = 1;
    private array $headers = ['X-Requested-With' => 'XMLHttpRequest'];

    private function register(string $email, ?string $sponsorRef = null): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'email' => $email,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'first_name' => 'U',
            'last_name' => $email,
            'sponsor_ref' => $sponsorRef,
        ], $this->headers)->assertOk()->json('token');
    }

    private function auth(string $token): array
    {
        return array_merge($this->headers, ['CalculatorAuthToken' => $token]);
    }

    public function testMeReturnsMemberAndRefLink(): void
    {
        $token = $this->register('root@t.dev');

        $res = $this->getJson('/api/v1/cabinet/me', $this->auth($token))->assertOk();
        $res->assertJsonPath('status', 'success');
        $this->assertNotEmpty($res->json('data.member.ref_code'));
        $this->assertStringContainsString('ref=', $res->json('data.ref_link'));
        $this->assertSame('registered', $res->json('data.member.status'));
    }

    public function testActivationReflectsIncomeOnDashboard(): void
    {
        $rootToken = $this->register('r@t.dev');
        $rootRef = $this->getJson('/api/v1/cabinet/me', $this->auth($rootToken))->json('data.member.ref_code');
        $aToken = $this->register('a@t.dev', $rootRef);

        // Спонсор активен сам + активируется личник → реферал $9 спонсору.
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->auth($rootToken))
            ->assertOk()->assertJsonPath('data.member.status', 'active');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->auth($aToken))
            ->assertOk();

        $dash = $this->getJson('/api/v1/cabinet/dashboard', $this->auth($rootToken))->assertOk();
        $this->assertEqualsWithDelta(9.0, (float) $dash->json('data.total'), 0.001);
        $this->assertEqualsWithDelta(9.0, (float) ($dash->json('data.by_type.referral') ?? 0), 0.001);
        $this->assertNotEmpty($dash->json('data.lines'));
        $this->assertSame('referral', $dash->json('data.lines.0.type'));
    }

    public function testTeamTreeContainsDownline(): void
    {
        $rootToken = $this->register('tr@t.dev');
        $rootRef = $this->getJson('/api/v1/cabinet/me', $this->auth($rootToken))->json('data.member.ref_code');
        $this->register('td@t.dev', $rootRef);

        $tree = $this->getJson('/api/v1/cabinet/team-tree', $this->auth($rootToken))->assertOk();
        $this->assertNotEmpty($tree->json('data.children'));
        $this->assertStringContainsString('td@t.dev', $tree->json('data.children.0.name'));
    }

    public function testDashboardIsIsolatedBetweenPartners(): void
    {
        $rootToken = $this->register('iso-r@t.dev');
        $rootRef = $this->getJson('/api/v1/cabinet/me', $this->auth($rootToken))->json('data.member.ref_code');
        $aToken = $this->register('iso-a@t.dev', $rootRef);
        $bToken = $this->register('iso-b@t.dev', $rootRef);

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->auth($rootToken));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->auth($aToken));

        // Root заработал реферал; B (не спонсор A) не должен видеть чужой доход.
        $rootDash = $this->getJson('/api/v1/cabinet/dashboard', $this->auth($rootToken))->json('data');
        $bDash = $this->getJson('/api/v1/cabinet/dashboard', $this->auth($bToken))->json('data');

        $this->assertEqualsWithDelta(9.0, (float) $rootDash['total'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $bDash['total'], 0.001);
        $this->assertEmpty($bDash['lines']);
    }

    public function testRankProgressReturnsCurrentAndNext(): void
    {
        $token = $this->register('rp@t.dev');

        $res = $this->getJson('/api/v1/cabinet/rank-progress', $this->auth($token))->assertOk();
        $this->assertArrayHasKey('next', $res->json('data'));
        $this->assertArrayHasKey('progress', $res->json('data'));
    }

    public function testCabinetReturns404WhenNoMember(): void
    {
        // Пользователь без участника (legacy/прямой токен) → 404.
        $user = CalculatorUser::create(['email' => 'nomember@t.dev', 'password' => Hash::make('secret123')]);
        $token = CalculatorUserToken::create([
            'calculator_user_id' => $user->id,
            'token' => hash('sha256', 'manual-token'),
            'expires_at' => now()->addDay(),
        ]);

        $this->getJson('/api/v1/cabinet/me', $this->auth($token->token))->assertStatus(404);
    }

    public function testActivateValidatesPackage(): void
    {
        $token = $this->register('val@t.dev');
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => 999], $this->auth($token))
            ->assertStatus(422);
    }

    public function testCabinetRequiresToken(): void
    {
        $this->getJson('/api/v1/cabinet/me', $this->headers)->assertStatus(403);
    }
}
