<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Кошелёк партнёра в кабинете (Фаза 3): баланс из ledger, лента движений,
 * изоляция между партнёрами, доступ только по initData.
 */
class WalletCabinetTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private const BRONZE = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    public function testWalletBalanceReflectsAccruedIncome(): void
    {
        [$rootData, $rootRef] = $this->registerTg(70, name: 'Root');
        [$aData] = $this->registerTg(71, $rootRef, 'A');

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData));

        $res = $this->getJson('/api/v1/cabinet/wallet', $this->tgHeaders($rootData))->assertOk();
        // Referral $9 → доступно "9.00", без холда и долга.
        $this->assertSame('9.00', $res->json('data.available'));
        $this->assertSame('0.00', $res->json('data.held'));
        $this->assertSame('0.00', $res->json('data.clawback_debt'));
    }

    public function testWalletTransactionsListAccrual(): void
    {
        [$rootData, $rootRef] = $this->registerTg(80, name: 'Root');
        [$aData] = $this->registerTg(81, $rootRef, 'A');

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData));

        $res = $this->getJson('/api/v1/cabinet/wallet/transactions', $this->tgHeaders($rootData))->assertOk();
        $this->assertNotEmpty($res->json('data.items'));
        $this->assertSame('accrual', $res->json('data.items.0.source_type'));
        $this->assertSame('9.00', $res->json('data.items.0.amount')); // начисление = + к доступному
    }

    public function testWalletIsolatedBetweenPartners(): void
    {
        [$rootData, $rootRef] = $this->registerTg(90, name: 'Root');
        [$aData] = $this->registerTg(91, $rootRef, 'A');
        [$bData] = $this->registerTg(92, $rootRef, 'B');

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData));

        // B (не спонсор A) не видит чужой доход — его кошелёк пуст.
        $res = $this->getJson('/api/v1/cabinet/wallet', $this->tgHeaders($bData))->assertOk();
        $this->assertSame('0.00', $res->json('data.available'));
        $this->assertEmpty($this->getJson('/api/v1/cabinet/wallet/transactions', $this->tgHeaders($bData))->json('data.items'));
    }

    public function testWalletRequiresTelegramInitData(): void
    {
        $this->getJson('/api/v1/cabinet/wallet', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
    }

    // --- A2: выписка партнёра за период ---

    public function testStatementListsAccrualWithSummary(): void
    {
        [$rootData, $rootRef] = $this->registerTg(100, name: 'Root');
        [$aData] = $this->registerTg(101, $rootRef, 'A');

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData));

        $res = $this->getJson('/api/v1/cabinet/wallet/statement', $this->tgHeaders($rootData))->assertOk();

        $this->assertNotEmpty($res->json('data.items'));
        $this->assertSame('accrual', $res->json('data.items.0.source_type'));
        $this->assertSame('9.00', $res->json('data.items.0.amount'));
        // Referral $9 поступил, ничего не списано.
        $this->assertSame(900, $res->json('data.summary.credited_cents'));
        $this->assertSame(0, $res->json('data.summary.debited_cents'));
        $this->assertSame(900, $res->json('data.summary.net_cents'));
    }

    public function testStatementFilteredByPeriod(): void
    {
        [$rootData, $rootRef] = $this->registerTg(110, name: 'Root');
        [$aData] = $this->registerTg(111, $rootRef, 'A');

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData));

        // Окно в прошлом (2000 год) — движений нет, сводка нулевая.
        $res = $this->getJson('/api/v1/cabinet/wallet/statement?from=2000-01-01&to=2000-01-31', $this->tgHeaders($rootData))->assertOk();
        $this->assertEmpty($res->json('data.items'));
        $this->assertSame(0, $res->json('data.summary.net_cents'));
    }

    public function testStatementIsolatedAndRequiresInitData(): void
    {
        [$rootData, $rootRef] = $this->registerTg(120, name: 'Root');
        [$aData] = $this->registerTg(121, $rootRef, 'A');
        [$bData] = $this->registerTg(122, $rootRef, 'B');

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData));

        // B (не спонсор A) не видит чужих начислений.
        $this->assertEmpty($this->getJson('/api/v1/cabinet/wallet/statement', $this->tgHeaders($bData))->json('data.items'));
        // Без initData — 401.
        $this->getJson('/api/v1/cabinet/wallet/statement', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
    }
}
