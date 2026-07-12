<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Status;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\AwardEntitlement;
use Modules\Calculator\V2\Models\OrderVolumeSnapshot;
use Modules\Calculator\V2\Services\Awards\AwardsStep;
use Tests\TestCase;

/**
 * T10 [гейт]: AwardsStep — грант наград в пайплайне пост-оплаты гейтится флагом
 * mh_v2_awards (deny-by-default). Достигнутый ранг (строка v2_rank_history) при
 * выключенном флаге НЕ порождает entitlement'ов; включённый — добирает по истории.
 */
class AwardsStepGateTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2Status;

    private string $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        $this->activateV2Policy();
        config([
            'calculator.payment_gateway' => 'fake',
            'calculator.walletpay_webhook_secret' => $this->secret,
        ]);
    }

    private function bronze(): Product
    {
        return Product::query()->create([
            'name' => 'Bronze', 'price_usdt_cents' => 9000, 'pv' => 90,
            'package_id' => 1, 'sku' => 'TARIFF-BRONZE', 'is_active' => true, 'sort' => 1,
        ]);
    }

    private function postWebhook(array $payload): TestResponse
    {
        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, $this->secret);

        return $this->call('POST', '/api/v1/webhooks/wallet-pay', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $json);
    }

    private function buyAndPay(string $data, int $productId): int
    {
        $orderId = $this->postJson('/api/v1/cabinet/orders', ['product_id' => $productId], $this->tgHeaders($data))->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))->json('data');
        $this->postWebhook([
            'external_ref' => "pay:{$pay['payment_id']}", 'status' => 'paid', 'amount_cents' => $pay['amount_cents'],
        ])->assertOk();

        return $orderId;
    }

    public function testAwardsStepGatedByFlag(): void
    {
        // Volume-слой ON (нужен снапшот заказа), статусы/награды OFF на входе.
        $this->enableFeatureFlags('mh_v2_volumes');
        $bronze = $this->bronze();
        [$buyerData] = $this->registerTg(600, name: 'Buyer');
        $buyerId = $this->memberByTg(600)->id;

        $orderId = $this->buyAndPay($buyerData, $bronze->id);
        $this->assertSame(1, OrderVolumeSnapshot::query()->where('order_id', $orderId)->count());

        // Покупатель достиг MANAGER (строка истории). Флаг наград ещё OFF.
        $this->seedRank($buyerId, StatusCode::MANAGER, CarbonImmutable::parse($this->memberByTg(600)->created_at));

        $step = app(AwardsStep::class);

        // Флаг OFF => ни одной награды даже при наличии ранга.
        $step->handle($orderId);
        $this->assertSame(0, AwardEntitlement::query()->where('member_id', $buyerId)->count());

        // Флаг ON => добор награды по истории рангов.
        $this->enableFeatureFlags('mh_v2_awards');
        $step->handle($orderId);
        $this->assertSame(1, AwardEntitlement::query()->where('member_id', $buyerId)->where('award_code', 'MANAGER')->count());

        // Повторный проход шага идемпотентен.
        $step->handle($orderId);
        $this->assertSame(1, AwardEntitlement::query()->where('member_id', $buyerId)->count());
    }
}
