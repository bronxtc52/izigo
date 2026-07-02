<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\AutoshipSubscription;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Repositories\EloquentNetworkRepository;
use Modules\Calculator\Repositories\EloquentPlanRepository;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\AutoshipService;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\Services\Telegram\TelegramNotifier;
use RuntimeException;
use Tests\TestCase;

/**
 * B3 (P1-hardening): autoship-цикл атомарен. Сбой ре-покупки/активации ПОСЛЕ списания
 * откатывает и само списание (раньше charge коммитился отдельно → деньги списаны,
 * активация потеряна). Poison-подписка не останавливает прогон остальных (P2-7).
 */
class AutoshipHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** ActivationService, «взрывающийся» на заданных участниках ПОСЛЕ charge (внутри markPaid). */
    private function poisonActivationsFor(array $memberIds): void
    {
        $poisoned = new class(
            app(EloquentNetworkRepository::class),
            app(EloquentPlanRepository::class),
            app(TelegramNotifier::class),
            app(LedgerService::class),
        ) extends ActivationService {
            public static array $poisonMemberIds = [];

            public function activate(int $memberId, int $packageId, string $idempotencyKey, ?string $displayName = null): \Modules\Calculator\Models\ActivationEvent
            {
                if (in_array($memberId, self::$poisonMemberIds, true)) {
                    throw new RuntimeException("poison activation m{$memberId}");
                }

                return parent::activate($memberId, $packageId, $idempotencyKey, $displayName);
            }
        };
        $poisoned::$poisonMemberIds = $memberIds;
        app()->instance(ActivationService::class, $poisoned);
    }

    private function member(int $tg): Member
    {
        return app(MemberService::class)->registerTelegram($tg, "U{$tg}", null);
    }

    private function bronze(): Product
    {
        return Product::query()->create([
            'name' => 'Bronze', 'price_usdt_cents' => 9000, 'pv' => 90,
            'package_id' => 1, 'sku' => 'TARIFF-BRONZE', 'is_active' => true, 'sort' => 1,
        ]);
    }

    private function seedBalance(int $memberId, int $cents): void
    {
        DB::transaction(fn () => app(LedgerService::class)->deposit($memberId, $cents, "seed:m{$memberId}"));
    }

    private function dueSub(Member $m, Product $p): AutoshipSubscription
    {
        return AutoshipSubscription::query()->create([
            'member_id' => $m->id, 'product_id' => $p->id, 'package_id' => $p->package_id,
            'interval_days' => 30, 'next_charge_at' => now()->subDay(),
            'status' => AutoshipSubscription::STATUS_ACTIVE, 'retry_stage' => 0,
        ]);
    }

    public function testChargeRolledBackWhenActivationFails(): void
    {
        $m = $this->member(3100);
        $p = $this->bronze();
        $this->seedBalance($m->id, 20000);
        $sub = $this->dueSub($m, $p);

        $this->poisonActivationsFor([$m->id]);
        $summary = app(AutoshipService::class)->runDue(now());

        // Сбой после charge → вся транзакция откатилась: деньги НЕ списаны,
        // подписка не сдвинута (повторит попытку следующим прогоном).
        $this->assertSame(['charged' => 0, 'retried' => 0, 'paused' => 0], $summary);
        $this->assertSame(20000, MemberWallet::query()->where('member_id', $m->id)->value('available_cents'));
        $fresh = $sub->fresh();
        $this->assertSame(AutoshipSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertTrue($fresh->next_charge_at->isPast());
        $this->assertNull($fresh->last_charge_at);
    }

    public function testPoisonSubscriptionDoesNotBlockOthers(): void
    {
        $poison = $this->member(3110);
        $healthy = $this->member(3111);
        $p = $this->bronze();
        $this->seedBalance($poison->id, 20000);
        $this->seedBalance($healthy->id, 20000);
        $this->dueSub($poison, $p);
        $healthySub = $this->dueSub($healthy, $p);

        $this->poisonActivationsFor([$poison->id]);
        $summary = app(AutoshipService::class)->runDue(now());

        // Прогон дошёл до второй подписки: она списана и продвинута.
        $this->assertSame(1, $summary['charged']);
        $this->assertSame(20000 - 9000, MemberWallet::query()->where('member_id', $healthy->id)->value('available_cents'));
        $this->assertTrue($healthySub->fresh()->next_charge_at->isFuture());
        // Poison не тронут деньгами.
        $this->assertSame(20000, MemberWallet::query()->where('member_id', $poison->id)->value('available_cents'));
    }
}
