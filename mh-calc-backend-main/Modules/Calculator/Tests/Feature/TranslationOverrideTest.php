<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\TranslationOverride;
use Modules\Calculator\Services\I18n\TranslationService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * C4 (Block C): редактируемые переводы (i18n-оверрайды). Покрывает: upsert/delete доступны
 * только owner (finance/support/plain → 403, без auth → 401), повторный upsert обновляет
 * без дубля (UNIQUE locale,key), кэш инвалидируется, public read отдаёт корректную карту,
 * эффективный перевод = оверрайд поверх статики (где оверрайда нет — дефолт остаётся в JSON).
 */
class TranslationOverrideTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** Owner + по одному из остальных ролей; возвращает их initData. */
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

    // --- Сервис ---

    public function testUpsertCreatesAndInvalidatesCache(): void
    {
        $svc = app(TranslationService::class);

        $this->assertSame([], $svc->overridesForLocale('ru')); // прогрев кэша (пусто)

        $svc->upsert('ru', 'featureFlags.saved', 'Готово');
        // Кэш сброшен при upsert — следующее чтение видит новое значение.
        $this->assertSame(['featureFlags.saved' => 'Готово'], $svc->overridesForLocale('ru'));
    }

    public function testUpsertSameKeyUpdatesNotDuplicates(): void
    {
        $svc = app(TranslationService::class);

        $svc->upsert('ru', 'common.save', 'Сохранить');
        $svc->upsert('ru', 'common.save', 'Сохранить!!');

        $this->assertSame(1, TranslationOverride::query()->where('locale', 'ru')->where('key', 'common.save')->count());
        $this->assertSame(['common.save' => 'Сохранить!!'], $svc->overridesForLocale('ru'));
    }

    public function testDeleteRevertsToDefault(): void
    {
        $svc = app(TranslationService::class);
        $svc->upsert('ru', 'common.save', 'X');
        $this->assertSame(['common.save' => 'X'], $svc->overridesForLocale('ru'));

        $svc->delete('ru', 'common.save');
        // Оверрайда больше нет — карта пуста (эффективный перевод = статика/дефолт фронта).
        $this->assertSame([], $svc->overridesForLocale('ru'));
        $this->assertDatabaseMissing('translation_overrides', ['locale' => 'ru', 'key' => 'common.save']);
    }

    public function testUnknownLocaleRejected(): void
    {
        $svc = app(TranslationService::class);
        $this->expectException(\InvalidArgumentException::class);
        $svc->upsert('xx', 'a.b', 'v');
    }

    // --- Public read ---

    public function testPublicReadReturnsMap(): void
    {
        $svc = app(TranslationService::class);
        $svc->upsert('ru', 'featureFlags.saved', 'Готово');
        $svc->upsert('kk', 'common.save', 'Сақтау');

        // Без auth — публичное чтение (логин-страница/Mini App нуждаются в строках до auth).
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];

        $all = $this->getJson('/api/v1/i18n/overrides', $headers)->assertOk()->json('data');
        $this->assertSame('Готово', $all['ru']['featureFlags.saved']);
        $this->assertSame('Сақтау', $all['kk']['common.save']);

        $ru = $this->getJson('/api/v1/i18n/overrides?locale=ru', $headers)->assertOk()->json('data');
        $this->assertSame(['featureFlags.saved' => 'Готово'], $ru);
        // Ключ без оверрайда отсутствует в карте → фронт возьмёт статический дефолт.
        $this->assertArrayNotHasKey('common.save', $ru);
    }

    public function testPublicReadEmptyWhenNoOverrides(): void
    {
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];
        // graceful: пустая карта — фронт работает на статике.
        $this->getJson('/api/v1/i18n/overrides', $headers)->assertOk()->assertJsonPath('data', []);
    }

    // --- Admin (owner) ---

    public function testOwnerListsUpsertsAndDeletes(): void
    {
        $r = $this->bootRoles();

        $this->getJson('/api/v1/admin/i18n/overrides', $this->adminHeaders($r['ownerData']))
            ->assertOk()->assertJsonPath('data', []);

        $this->postJson('/api/v1/admin/i18n/overrides', ['locale' => 'ru', 'key' => 'common.save', 'value' => 'Сохранить'], $this->adminHeaders($r['ownerData']))
            ->assertOk()
            ->assertJsonPath('data.0.locale', 'ru')
            ->assertJsonPath('data.0.key', 'common.save')
            ->assertJsonPath('data.0.value', 'Сохранить');

        $this->assertDatabaseHas('translation_overrides', ['locale' => 'ru', 'key' => 'common.save', 'value' => 'Сохранить']);
        $this->assertSame($this->memberByTg(800)->id, TranslationOverride::query()->first()->updated_by);

        $this->deleteJson('/api/v1/admin/i18n/overrides', ['locale' => 'ru', 'key' => 'common.save'], $this->adminHeaders($r['ownerData']))
            ->assertOk()->assertJsonPath('data', []);
        $this->assertDatabaseMissing('translation_overrides', ['locale' => 'ru', 'key' => 'common.save']);
    }

    public function testUpsertValidationRejectsBadPayload(): void
    {
        $r = $this->bootRoles();

        $this->postJson('/api/v1/admin/i18n/overrides', ['key' => 'a.b', 'value' => 'x'], $this->adminHeaders($r['ownerData']))
            ->assertStatus(422); // нет locale
        $this->postJson('/api/v1/admin/i18n/overrides', ['locale' => 'zz', 'key' => 'a.b', 'value' => 'x'], $this->adminHeaders($r['ownerData']))
            ->assertStatus(422); // неизвестная локаль (сервис)
    }

    // --- RBAC negative-cases ---

    public function testFinanceCannotManage(): void
    {
        $r = $this->bootRoles();

        $this->getJson('/api/v1/admin/i18n/overrides', $this->adminHeaders($r['financeData']))->assertStatus(403);
        $this->postJson('/api/v1/admin/i18n/overrides', ['locale' => 'ru', 'key' => 'a.b', 'value' => 'x'], $this->adminHeaders($r['financeData']))
            ->assertStatus(403);
        $this->deleteJson('/api/v1/admin/i18n/overrides', ['locale' => 'ru', 'key' => 'a.b'], $this->adminHeaders($r['financeData']))
            ->assertStatus(403);

        $this->assertDatabaseCount('translation_overrides', 0);
    }

    public function testSupportCannotManage(): void
    {
        $r = $this->bootRoles();
        $this->postJson('/api/v1/admin/i18n/overrides', ['locale' => 'ru', 'key' => 'a.b', 'value' => 'x'], $this->adminHeaders($r['supportData']))
            ->assertStatus(403);
    }

    public function testPlainPartnerCannotManage(): void
    {
        $r = $this->bootRoles();
        $this->postJson('/api/v1/admin/i18n/overrides', ['locale' => 'ru', 'key' => 'a.b', 'value' => 'x'], $this->adminHeaders($r['plainData']))
            ->assertStatus(403);
    }

    public function testUnauthenticatedCannotManageButCanRead(): void
    {
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];

        $this->getJson('/api/v1/admin/i18n/overrides', $headers)->assertStatus(401);
        $this->postJson('/api/v1/admin/i18n/overrides', ['locale' => 'ru', 'key' => 'a.b', 'value' => 'x'], $headers)->assertStatus(401);
        $this->deleteJson('/api/v1/admin/i18n/overrides', ['locale' => 'ru', 'key' => 'a.b'], $headers)->assertStatus(401);

        // Public read остаётся доступным без auth.
        $this->getJson('/api/v1/i18n/overrides', $headers)->assertOk();

        $this->assertDatabaseCount('translation_overrides', 0);
    }
}
