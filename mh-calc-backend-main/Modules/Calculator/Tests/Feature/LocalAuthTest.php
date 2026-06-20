<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Calculator\Models\CalculatorUser;
use Tests\TestCase;

class LocalAuthTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = ['X-Requested-With' => 'XMLHttpRequest'];

    public function testRegisterCreatesUserAndReturnsToken(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'new@test.dev',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'first_name' => 'New',
            'last_name' => 'User',
            'language' => 'ru',
            'currency' => 'KZT',
        ], $this->headers);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['status', 'token', 'email', 'profile']);

        $this->assertDatabaseHas('calculator_users', ['email' => 'new@test.dev']);
        $user = CalculatorUser::where('email', 'new@test.dev')->first();
        $this->assertNotNull($user->password);
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        CalculatorUser::create(['email' => 'dup@test.dev', 'password' => Hash::make('secret123')]);

        $this->postJson('/api/v1/auth/register', [
            'email' => 'dup@test.dev',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $this->headers)->assertStatus(422);
    }

    public function testRegisterRejectsShortPassword(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'weak@test.dev',
            'password' => '123',
            'password_confirmation' => '123',
        ], $this->headers)->assertStatus(422);
    }

    public function testLoginWithValidCredentialsReturnsToken(): void
    {
        CalculatorUser::create(['email' => 'log@test.dev', 'password' => Hash::make('secret123')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'log@test.dev',
            'password' => 'secret123',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['status', 'token', 'email', 'profile']);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        CalculatorUser::create(['email' => 'log2@test.dev', 'password' => Hash::make('secret123')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'log2@test.dev',
            'password' => 'wrongpass',
        ], $this->headers)->assertStatus(401);
    }

    public function testLoginWithUnknownEmailFails(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ghost@test.dev',
            'password' => 'secret123',
        ], $this->headers)->assertStatus(401);
    }

    public function testSsoLoginRouteRemoved(): void
    {
        // SSO-вход (POST /api/v1/login) удалён — маршрута больше нет
        $this->postJson('/api/v1/login', [
            'sso_token' => 'whatever',
        ], $this->headers)->assertStatus(404);
    }

    public function testIssuedTokenPassesValidationMiddleware(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'email' => 'tok@test.dev',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $this->headers)->json();

        $token = $register['token'];

        // защищённый эндпоинт: создание структуры (201 Created при успехе)
        $this->postJson('/api/v1/calculator/structure', [], array_merge($this->headers, [
            'CalculatorAuthToken' => $token,
        ]))->assertCreated();
    }
}
