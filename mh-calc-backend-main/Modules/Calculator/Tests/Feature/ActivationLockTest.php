<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\AutoshipSubscription;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\AutoshipService;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\Services\MemberService;
use Tests\TestCase;

/**
 * B2 (P1-hardening): глобальный advisory-lock активаций. Конкурентные пересчёты сети
 * (delete/rewrite снапшота + дельта-проводки) без сериализации задваивают начисления.
 * Настоящую параллельность в PHPUnit не смоделировать — доказываем, что лок реально
 * берётся на обоих путях (activate и autoship), вторым DB-коннектом, держащим лок.
 */
class ActivationLockTest extends TestCase
{
    use RefreshDatabase;

    /** Второй коннект (отдельная сессия Postgres), держащий advisory-lock. */
    private function holdLockOnSecondConnection(): void
    {
        config(['database.connections.pgsql_lock_holder' => config('database.connections.' . config('database.default'))]);
        DB::connection('pgsql_lock_holder')->beginTransaction();
        DB::connection('pgsql_lock_holder')
            ->statement('SELECT pg_advisory_xact_lock(?)', [ActivationService::ACTIVATION_LOCK_KEY]);
    }

    private function releaseLock(): void
    {
        DB::connection('pgsql_lock_holder')->rollBack();
        DB::purge('pgsql_lock_holder');
    }

    public function testActivateBlocksOnHeldAdvisoryLock(): void
    {
        $member = app(MemberService::class)->registerTelegram(3000, 'U3000', null);
        $this->holdLockOnSecondConnection();

        try {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/lock timeout|canceling statement/i');

            DB::transaction(function () use ($member) {
                // SET LOCAL — только на эту транзакцию, коннект не загрязняется.
                DB::statement("SET LOCAL lock_timeout = '250ms'");
                app(ActivationService::class)->activate($member->id, 1, 'evt-lock-probe');
            });
        } finally {
            $this->releaseLock();
        }
    }

    public function testAutoshipTakesSameLockBeforeCharge(): void
    {
        $member = app(MemberService::class)->registerTelegram(3010, 'U3010', null);
        $product = Product::query()->create([
            'name' => 'Bronze', 'price_usdt_cents' => 9000, 'pv' => 90,
            'package_id' => 1, 'sku' => 'TARIFF-BRONZE', 'is_active' => true, 'sort' => 1,
        ]);
        DB::transaction(fn () => app(LedgerService::class)->deposit($member->id, 20000, "seed:m{$member->id}"));
        $sub = AutoshipSubscription::query()->create([
            'member_id' => $member->id, 'product_id' => $product->id, 'package_id' => $product->package_id,
            'interval_days' => 30, 'next_charge_at' => now()->subDay(),
            'status' => AutoshipSubscription::STATUS_ACTIVE, 'retry_stage' => 0,
        ]);

        $this->holdLockOnSecondConnection();

        try {
            $summary = DB::transaction(function () {
                DB::statement("SET LOCAL lock_timeout = '250ms'");

                // Лок занят → processOne падает по таймауту, runDue ловит (poison-защита),
                // транзакция подписки откатывается ЦЕЛИКОМ — списания нет.
                return app(AutoshipService::class)->runDue(now());
            });
        } finally {
            $this->releaseLock();
        }

        $this->assertSame(['charged' => 0, 'retried' => 0, 'paused' => 0], $summary);
        $this->assertSame(20000, MemberWallet::query()->where('member_id', $member->id)->value('available_cents'));
        $this->assertSame(0, $sub->fresh()->retry_stage);
    }
}
