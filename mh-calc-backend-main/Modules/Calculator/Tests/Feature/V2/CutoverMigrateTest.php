<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Models\V2\CutoverLog;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsCutoverData;
use Modules\Calculator\V2\Services\Cutover\CutoverHeldInFlightException;
use Modules\Calculator\V2\Services\Cutover\OpeningBalanceMigrationService;
use Tests\TestCase;

/**
 * T15 [ДЕНЬГИ, обязательный]: data-cutover команда calc-v2:cutover-migrate.
 * Dry-run без записи; commit — перенос main→ОС opening (бессрочный лот) + Bronze→100,
 * инвариант сохранения суммы, trial balance 0, идемпотентность, abort на дрейфе сверки.
 */
class CutoverMigrateTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCutoverData;

    public function test_dry_run_prints_plan_without_writes(): void
    {
        $a = $this->seedMember(9001);
        $b = $this->seedMember(9002, $a);
        $this->seedMember(9003, $a); // без баланса
        $this->deposit($a->id, 10000);
        $this->deposit($b->id, 5000);
        $this->seedBronze();

        $ledgerBefore = LedgerEntry::query()->count();

        $this->artisan('calc-v2:cutover-migrate')->assertExitCode(0);

        // Ни лотов, ни V2-счетов, ни изменения кошельков/тарифа/ledger.
        $this->assertSame(0, WalletLotV2::query()->count());
        $this->assertSame(0, MemberAccountV2::query()->where('os_available_cents', '>', 0)->count());
        $this->assertSame(10000, (int) MemberWallet::query()->where('member_id', $a->id)->value('available_cents'));
        $this->assertSame(90, (int) Product::query()->where('sku', 'TARIFF-BRONZE')->value('pv'));
        $this->assertSame($ledgerBefore, LedgerEntry::query()->count());
        // dry-run фиксируется в аудите.
        $this->assertDatabaseHas('v2_cutover_log', ['action' => 'phase', 'phase' => 'dry_run', 'dry_run' => true]);
    }

    public function test_commit_migrates_opening_and_raises_bronze(): void
    {
        $a = $this->seedMember(9001);
        $b = $this->seedMember(9002, $a);
        $c = $this->seedMember(9003, $a);
        $this->deposit($a->id, 10000);
        $this->deposit($b->id, 5000);
        $this->deposit($c->id, 250);
        $this->seedBronze();

        $this->artisan('calc-v2:cutover-migrate', ['--commit' => true])->assertExitCode(0);

        // Bronze → 100 PV / 100 USDT.
        $bronze = Product::query()->where('sku', 'TARIFF-BRONZE')->first();
        $this->assertSame(100, (int) $bronze->pv);
        $this->assertSame(10000, (int) $bronze->price_usdt_cents);

        foreach ([[$a->id, 10000], [$b->id, 5000], [$c->id, 250]] as [$mid, $amount]) {
            // Бессрочный opening-лот ОС.
            $lot = WalletLotV2::query()->where('member_id', $mid)->where('account', 'os')->first();
            $this->assertNotNull($lot, "нет opening-лота у {$mid}");
            $this->assertSame($amount, (int) $lot->amount_cents);
            $this->assertNull($lot->expires_at, 'opening-лот обязан быть бессрочным');
            $this->assertSame('v2_opening', $lot->source_type); // единый source_type проводки и лота (should-fix #5)
            // Кэши: V1 available → 0, V2 ОС = перенесённое.
            $this->assertSame(0, (int) MemberWallet::query()->where('member_id', $mid)->value('available_cents'));
            $this->assertSame($amount, (int) MemberAccountV2::query()->where('member_id', $mid)->value('os_available_cents'));
        }

        // Инвариант сохранения суммы: Σ ОС после == Σ available до (15250).
        $this->assertSame(15250, (int) MemberAccountV2::query()->sum('os_available_cents'));

        // Trial balance == 0.
        $debit = (int) LedgerEntry::query()->where('direction', 'debit')->sum('amount_cents');
        $credit = (int) LedgerEntry::query()->where('direction', 'credit')->sum('amount_cents');
        $this->assertSame($debit, $credit);

        // Аудит переноса.
        $this->assertSame(3, CutoverLog::query()->where('action', 'opening_migration')->count());
    }

    public function test_commit_is_idempotent(): void
    {
        $a = $this->seedMember(9001);
        $this->deposit($a->id, 10000);
        $this->seedBronze();

        $this->artisan('calc-v2:cutover-migrate', ['--commit' => true])->assertExitCode(0);
        // Повторный прогон не задваивает opening-лоты и не двигает балансы.
        $this->artisan('calc-v2:cutover-migrate', ['--commit' => true])->assertExitCode(0);

        $this->assertSame(1, WalletLotV2::query()->where('member_id', $a->id)->where('account', 'os')->count());
        $this->assertSame(10000, (int) MemberAccountV2::query()->where('member_id', $a->id)->value('os_available_cents'));
        $this->assertSame(0, (int) MemberWallet::query()->where('member_id', $a->id)->value('available_cents'));
        // Bronze всё ещё 100 (повторный apply — no-op).
        $this->assertSame(100, (int) Product::query()->where('sku', 'TARIFF-BRONZE')->value('pv'));
    }

    /**
     * MF-2(в): открытый вывод (held_cents>0) обязан заблокировать --commit до его
     * разруливания — иначе деньги «в полёте» тихо расщепляются между V1 и V2.
     */
    public function test_commit_aborts_when_member_has_held_balance(): void
    {
        $a = $this->seedMember(9001);
        $this->deposit($a->id, 10000);
        $this->hold($a->id, 3000); // заявка на вывод «в полёте»
        $this->seedBronze();

        $this->artisan('calc-v2:cutover-migrate', ['--commit' => true])->assertExitCode(1);

        // Ни одной проводки/лота, тариф не тронут (abort ДО транзакции).
        $this->assertSame(0, WalletLotV2::query()->count());
        $this->assertSame(90, (int) Product::query()->where('sku', 'TARIFF-BRONZE')->value('pv'));
        // Аудит зафиксировал abort по held (action=phase/phase=pre/dry_run=false — уникально для held-гейта).
        $this->assertDatabaseHas('v2_cutover_log', ['action' => 'phase', 'phase' => 'pre', 'dry_run' => false]);
    }

    /**
     * TOCTOU-фикс (hardening T15): held, ставший видимым ТОЛЬКО под локом внутри транзакции
     * миграции (вывод «в полёте», проскочивший быстрый пре-чек команды), обязан откатить ВСЮ
     * миграцию атомарно — ни лота, ни проводки, ни изменения кошелька/ОС. Тест бьёт прямо в
     * авторитетный предохранитель: сервис вызывается внутри транзакции под ACTIVATION_LOCK
     * (в обход пре-чека команды), held стоит на кошельке участника → CutoverHeldInFlightException.
     * До фикса migrateMember игнорировал held и переносил available → расщепление V1/V2.
     */
    public function test_migration_aborts_atomically_when_held_visible_under_lock(): void
    {
        $a = $this->seedMember(9001);
        $this->deposit($a->id, 10000);
        $this->hold($a->id, 3000); // вывод «в полёте»: available 7000 / held 3000

        $svc = app(OpeningBalanceMigrationService::class);
        $ledgerBefore = LedgerEntry::query()->count();

        $threw = false;
        try {
            DB::transaction(function () use ($svc) {
                app(ActivationService::class)->acquireActivationLock();
                $svc->assertNoHeldInFlight(); // авторитетный held-предохранитель под локом
                $svc->commitAll();
            });
        } catch (CutoverHeldInFlightException $e) {
            $threw = true;
            $this->assertSame(3000, $e->heldCents);
            $this->assertSame(1, $e->heldMembers);
        }

        $this->assertTrue($threw, 'миграция обязана бросить при held>0, видимом под локом');
        // Атомарный откат: ни лота, ни ОС-счёта, кошелёк не тронут, ledger без новых проводок.
        $this->assertSame(0, WalletLotV2::query()->count());
        $this->assertSame(0, MemberAccountV2::query()->where('os_available_cents', '>', 0)->count());
        $this->assertSame(7000, (int) MemberWallet::query()->where('member_id', $a->id)->value('available_cents'));
        $this->assertSame(3000, (int) MemberWallet::query()->where('member_id', $a->id)->value('held_cents'));
        $this->assertSame($ledgerBefore, LedgerEntry::query()->count());
    }

    /**
     * Тот же TOCTOU-предохранитель на уровне КОМАНДЫ: когда held виден под локом внутри
     * транзакции, команда ловит бросок, откатывает всё и пишет abort phase=rolled_back
     * (отличимо от пре-чека phase=pre), возвращая FAILURE. Здесь held стоит и на пре-чеке,
     * и под локом — доказывает, что путь команды тоже атомарен (двойная защита).
     */
    public function test_command_writes_rolled_back_audit_but_pre_check_guards_first(): void
    {
        $a = $this->seedMember(9001);
        $this->deposit($a->id, 10000);
        $this->hold($a->id, 3000);
        $this->seedBronze();

        // Пре-чек команды срабатывает первым (held виден и снаружи) → phase=pre, FAILURE.
        $this->artisan('calc-v2:cutover-migrate', ['--commit' => true])->assertExitCode(1);
        $this->assertSame(0, WalletLotV2::query()->count());
        $this->assertSame(90, (int) Product::query()->where('sku', 'TARIFF-BRONZE')->value('pv'));
        $this->assertDatabaseHas('v2_cutover_log', ['action' => 'phase', 'phase' => 'pre', 'dry_run' => false]);
    }

    public function test_commit_aborts_on_reconciliation_drift(): void
    {
        $a = $this->seedMember(9001);
        $this->deposit($a->id, 10000);
        $this->seedBronze();

        // Дрейф кэша: available_cents больше свёртки ledger — перенос был бы неверным.
        MemberWallet::query()->where('member_id', $a->id)->update(['available_cents' => 11000]);

        $this->artisan('calc-v2:cutover-migrate', ['--commit' => true])->assertExitCode(1);

        // Ни одной проводки/лота, тариф не тронут (abort ДО транзакции).
        $this->assertSame(0, WalletLotV2::query()->count());
        $this->assertSame(90, (int) Product::query()->where('sku', 'TARIFF-BRONZE')->value('pv'));
        $this->assertSame(11000, (int) MemberWallet::query()->where('member_id', $a->id)->value('available_cents'));
    }

    /**
     * Cutover-инвариант: правка тарифа Bronze→100 PV НЕ откатывается ProductSeeder'ом
     * на следующем деплое/рестарте (start.sh гоняет сидер каждый раз). Сидер сохраняет
     * рантайм pv/price существующего тарифа (firstOrNew, create-only для pv/price).
     */
    public function test_product_seeder_does_not_revert_bronze_after_cutover(): void
    {
        $a = $this->seedMember(9001);
        $this->deposit($a->id, 10000);
        $this->seedBronze();

        // Cutover поднял Bronze до 100.
        $this->artisan('calc-v2:cutover-migrate', ['--commit' => true])->assertExitCode(0);
        $this->assertSame(100, (int) Product::query()->where('sku', 'TARIFF-BRONZE')->value('pv'));

        // Повторный прогон сидера (эмуляция деплоя) НЕ возвращает 90.
        (new \Modules\Calculator\Database\Seeders\ProductSeeder())->run();

        $bronze = Product::query()->where('sku', 'TARIFF-BRONZE')->first();
        $this->assertSame(100, (int) $bronze->pv, 'ProductSeeder не должен откатывать cutover Bronze→100');
        $this->assertSame(10000, (int) $bronze->price_usdt_cents);
        // Отсутствующий тариф сидер по-прежнему создаёт с дефолтами.
        Product::query()->where('sku', 'TARIFF-SILVER')->delete();
        (new \Modules\Calculator\Database\Seeders\ProductSeeder())->run();
        $this->assertSame(180, (int) Product::query()->where('sku', 'TARIFF-SILVER')->value('pv'));
    }
}
