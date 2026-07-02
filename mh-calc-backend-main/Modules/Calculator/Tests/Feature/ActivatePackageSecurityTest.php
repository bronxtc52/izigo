<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\ActivationEvent;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Регресс на блокеры безопасности денег (аудит docs/reviews/2026-07-02-production-review.md):
 *
 *  B-1 — бесплатная активация: POST /cabinet/activate-package с Фазы 3 пишет реальные выводимые
 *        бонусы в ledger. В проде эндпоинт должен быть выключен (deny-by-default), иначе любой
 *        участник «печатает деньги» без оплаты/роли/флага.
 *  B-2 — отравление idempotency_key: клиентский ключ не должен попадать в глобальный namespace
 *        activation_events.idempotency_key. Иначе атакующий занимает предсказуемый системный
 *        ключ `order:{id}` → реальный оплаченный заказ получает inserted===0 и активацию не
 *        применяет (жертва платит — бонусов нет).
 */
class ActivatePackageSecurityTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private const BRONZE = 1;

    private string $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config([
            'calculator.payment_gateway' => 'fake',
            'calculator.walletpay_webhook_secret' => $this->secret,
        ]);
    }

    // ── B-1 ──────────────────────────────────────────────────────────────────

    /** В тест-окружении флаг ON (phpunit.xml) — эндпоинт-фикстура доступен. */
    public function testMockActivationAvailableWhenFlagEnabled(): void
    {
        [$initData] = $this->registerTg(700, name: 'Root');

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($initData))
            ->assertOk()
            ->assertJsonPath('data.member.status', 'active');
    }

    /**
     * Прод-поведение: флаг OFF → роут отвечает 404 (эффективно вне API). Гейт читает config
     * per-request (middleware), поэтому проверяется рантайм-переключением без пересборки роутов.
     */
    public function testMockActivationBlockedInProductionWhenFlagDisabled(): void
    {
        [$initData] = $this->registerTg(701, name: 'Root');

        config(['calculator.allow_mock_activation' => false]);

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($initData))
            ->assertStatus(404);

        // И ничего не активировалось: событий активации нет, участник не стал active.
        $this->assertSame(0, ActivationEvent::query()->count());
        $this->assertSame('registered', $this->memberByTg(701)->fresh()->status);
    }

    // ── B-2 ──────────────────────────────────────────────────────────────────

    /**
     * Клиентский idempotency_key игнорируется: даже прислав `order:{...}` в теле, участник не
     * может записать этот ключ в глобальный namespace — сервер генерирует свой `activate:*`.
     */
    public function testClientCannotInjectSystemIdempotencyKey(): void
    {
        [$initData] = $this->registerTg(710, name: 'Root');
        $member = $this->memberByTg(710);

        $this->postJson(
            '/api/v1/cabinet/activate-package',
            ['package_id' => self::BRONZE, 'idempotency_key' => 'order:999999'],
            $this->tgHeaders($initData),
        )->assertOk();

        // Клиентский ключ отброшен: события с чужим системным ключом нет.
        $this->assertFalse(
            ActivationEvent::query()->where('idempotency_key', 'order:999999')->exists(),
            'Клиентский idempotency_key не должен попадать в глобальный namespace',
        );
        // Событие создано под серверным ключом собственного namespace активаций.
        $this->assertTrue(
            ActivationEvent::query()
                ->where('idempotency_key', "activate:m{$member->id}:p" . self::BRONZE)
                ->exists(),
        );
    }

    /**
     * E2E: попытка «занять» ключ реального заказа `order:{id}` снаружи (через activate-package)
     * не мешает активации этого заказа при оплате — жертва получает активацию и спонсор бонус.
     */
    public function testPoisonAttemptDoesNotBlockRealOrderActivation(): void
    {
        $bronze = Product::query()->create([
            'name' => 'Bronze', 'price_usdt_cents' => 9000, 'pv' => 90,
            'package_id' => self::BRONZE, 'sku' => 'TARIFF-BRONZE', 'is_active' => true, 'sort' => 1,
        ]);

        [$rootData, $rootRef] = $this->registerTg(720, name: 'Root');
        [$victimData] = $this->registerTg(721, $rootRef, 'Victim');
        [$attackerData] = $this->registerTg(722, $rootRef, 'Attacker');

        // Спонсор активен (иначе реферал не начисляется) — через оплаченный заказ.
        $this->buyAndPay($rootData, $bronze->id);

        // Жертва создаёт заказ (ещё не оплачен). Его будущий ключ активации — order:{orderId}.
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $bronze->id], $this->tgHeaders($victimData))->json('data.id');

        // Атакующий пытается заранее занять order:{orderId} через мок-активацию.
        $this->postJson(
            '/api/v1/cabinet/activate-package',
            ['package_id' => self::BRONZE, 'idempotency_key' => "order:{$orderId}"],
            $this->tgHeaders($attackerData),
        )->assertOk();

        // Ключ заказа НЕ занят атакующим (до оплаты события order:{orderId} не существует).
        $this->assertFalse(
            ActivationEvent::query()->where('idempotency_key', "order:{$orderId}")->exists(),
        );

        // Жертва оплачивает заказ → реальная активация проходит штатно.
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($victimData))->json('data');
        $this->postWebhook([
            'external_ref' => "pay:{$pay['payment_id']}", 'status' => 'paid', 'amount_cents' => $pay['amount_cents'],
        ])->assertOk();

        $order = Order::find($orderId);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->activation_event_id);
        $this->assertSame('active', $this->memberByTg(721)->fresh()->status);
        $this->assertTrue(
            ActivationEvent::query()->where('idempotency_key', "order:{$orderId}")->exists(),
        );
    }

    private function buyAndPay(string $data, int $productId): int
    {
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $productId], $this->tgHeaders($data))->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))->json('data');
        $this->postWebhook([
            'external_ref' => "pay:{$pay['payment_id']}", 'status' => 'paid', 'amount_cents' => $pay['amount_cents'],
        ])->assertOk();

        return $orderId;
    }

    private function postWebhook(array $payload): TestResponse
    {
        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, $this->secret);

        return $this->call('POST', '/api/v1/webhooks/wallet-pay', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $json);
    }
}
