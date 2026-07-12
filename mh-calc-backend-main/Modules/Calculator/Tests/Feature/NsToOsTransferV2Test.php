<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\V2\Contracts\NsToOsTransfer;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;
use Tests\TestCase;

/**
 * mh-full-plan T02 (деньги): перевод НС→ОС после месячной калибровки (MF-4/MF-6:
 * T02 владеет ОПЕРАЦИЕЙ executeForCalibratedMonth; команда/расписание — T04).
 * paid = intdiv(raw × factor_bps, 10000); дельта — в sink company_pool_retained (fixloop).
 */
class NsToOsTransferV2Test extends TestCase
{
    use RefreshDatabase;

    private WalletAccountsV2Service $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        // Фиксированное «сейчас» — дефолтная атрибуция НС-кредитов идёт в месяц 2026-07.
        $this->travelTo(\Carbon\Carbon::parse('2026-07-10 12:00:00', 'UTC'));
        $this->wallet = app(WalletAccountsV2Service::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function memberWithNs(int $nsCents, ?string $accrualMonth = null): Member
    {
        $m = app(MemberService::class)->registerTelegram(random_int(10000, 99999999), 'N', null);
        $this->wallet->credit($m->id, 'ns', $nsCents, "v2:t:seed-ns:{$m->id}", accrualMonth: $accrualMonth);

        return $m;
    }

    private function account(int $memberId): MemberAccountV2
    {
        return MemberAccountV2::query()->where('member_id', $memberId)->firstOrFail();
    }

    private function poolRetained(): int
    {
        return (int) LedgerEntry::query()
            ->where('account_type', LedgerPostingV2Service::ACC_POOL_RETAINED)
            ->where('direction', 'credit')->sum('amount_cents');
    }

    public function testFullFactorTransfersEverything(): void
    {
        $m = $this->memberWithNs(10000);
        $this->wallet->executeForCalibratedMonth('2026-07', 10000);

        $a = $this->account($m->id);
        $this->assertSame(0, $a->ns_cents);
        $this->assertSame(10000, $a->os_available_cents);
        $this->assertSame(0, $this->poolRetained());

        // Создан ОС-лот с годовым сроком от даты перевода (BR-ACC-001).
        $lot = WalletLotV2::query()->where('member_id', $m->id)->where('account', 'os')->sole();
        $this->assertSame(10000, $lot->available_cents);
        $this->assertSame('ns_transfer', $lot->source_type);
        $this->assertSame(now()->addDays(365)->toDateString(), $lot->expires_at->toDateString());
    }

    public function testPartialFactorSendsDeltaToPoolRetained(): void
    {
        // Worked example калибровки (amendments): raw 10000, factor 6000 → paid 6000, дельта 4000.
        $m = $this->memberWithNs(10000);
        $this->wallet->executeForCalibratedMonth('2026-07', 6000);

        $a = $this->account($m->id);
        $this->assertSame(0, $a->ns_cents);
        $this->assertSame(6000, $a->os_available_cents);
        $this->assertSame(4000, $this->poolRetained());

        // Целочисленная математика: intdiv, без float — нечётный пример.
        $m2 = $this->memberWithNs(9999, '2026-08');
        $this->wallet->executeForCalibratedMonth('2026-08', 6000);
        // intdiv(9999*6000,10000) = 5999
        $this->assertSame(5999, $this->account($m2->id)->os_available_cents);
        $this->assertSame(4000 + 4000, $this->poolRetained());
    }

    public function testZeroFactorRetainsAllAndCreatesNoLot(): void
    {
        $m = $this->memberWithNs(5000);
        $this->wallet->executeForCalibratedMonth('2026-07', 0);

        $a = $this->account($m->id);
        $this->assertSame(0, $a->ns_cents);
        $this->assertSame(0, $a->os_available_cents);
        $this->assertSame(5000, $this->poolRetained());
        $this->assertSame(0, WalletLotV2::query()->where('member_id', $m->id)->where('account', 'os')->count());
    }

    public function testIdempotentPerMonth(): void
    {
        $m = $this->memberWithNs(10000);
        $this->wallet->executeForCalibratedMonth('2026-07', 10000);
        // НС пополнился начислением СЛЕДУЮЩЕГО месяца; повтор ЗА ТОТ ЖЕ месяц —
        // no-op (ключ на окно, DEC-019), августовский транш не тронут.
        $this->wallet->credit($m->id, 'ns', 7000, "v2:t:more-ns:{$m->id}", accrualMonth: '2026-08');
        $this->wallet->executeForCalibratedMonth('2026-07', 10000);

        $a = $this->account($m->id);
        $this->assertSame(7000, $a->ns_cents);          // второй транш не тронут
        $this->assertSame(10000, $a->os_available_cents);

        // Калибровка следующего месяца переводит его начисления.
        $this->wallet->executeForCalibratedMonth('2026-08', 10000);
        $this->assertSame(0, $this->account($m->id)->ns_cents);
        $this->assertSame(17000, $this->account($m->id)->os_available_cents);
    }

    public function testTransfersOnlyCalibratedMonthAccruals(): void
    {
        // Ревью W1 MF-3: перевод откалиброванного месяца НЕ должен уносить весь
        // плоский НС — начисления следующего месяца остаются на НС до ЕГО калибровки
        // (иначе они уедут в ОС под чужим factor_bps — искажение денег).
        $m = $this->memberWithNs(10000, '2026-07');
        $this->wallet->credit($m->id, 'ns', 5000, "v2:t:aug-ns:{$m->id}", accrualMonth: '2026-08');

        $this->wallet->executeForCalibratedMonth('2026-07', 6000);

        $a = $this->account($m->id);
        $this->assertSame(6000, $a->os_available_cents, 'июль: intdiv(10000*6000,10000)');
        $this->assertSame(5000, $a->ns_cents, 'MF-3: августовские начисления должны остаться на НС');
        $this->assertSame(4000, $this->poolRetained());

        // Калибровка августа переводит ТОЛЬКО августовский транш под СВОЙ фактор.
        $this->wallet->executeForCalibratedMonth('2026-08', 10000);
        $a = $this->account($m->id);
        $this->assertSame(0, $a->ns_cents);
        $this->assertSame(11000, $a->os_available_cents);
        $this->assertSame(4000, $this->poolRetained());
    }

    public function testDefaultAccrualMonthIsCurrentUtcMonth(): void
    {
        // Без явного accrualMonth НС-кредит атрибутируется месяцу now() UTC (2026-07).
        $m = $this->memberWithNs(3000);
        $this->wallet->executeForCalibratedMonth('2026-06', 10000);
        $this->assertSame(3000, $this->account($m->id)->ns_cents, 'июньская калибровка не должна уносить июльское начисление');

        $this->wallet->executeForCalibratedMonth('2026-07', 10000);
        $this->assertSame(0, $this->account($m->id)->ns_cents);
        $this->assertSame(3000, $this->account($m->id)->os_available_cents);
    }

    public function testAccrualMonthRejectedForNonNsAndBadFormat(): void
    {
        $m = app(MemberService::class)->registerTelegram(random_int(10000, 99999999), 'V', null);
        try {
            $this->wallet->credit($m->id, 'os', 1000, "v2:t:os-month:{$m->id}", accrualMonth: '2026-07');
            $this->fail('accrualMonth для ОС должен отклоняться');
        } catch (\DomainException) {
        }
        $this->expectException(\DomainException::class);
        $this->wallet->credit($m->id, 'ns', 1000, "v2:t:bad-month:{$m->id}", accrualMonth: '2026-13');
    }

    public function testEmptyNsIsNoOp(): void
    {
        $m = app(MemberService::class)->registerTelegram(random_int(10000, 99999999), 'E', null);
        $this->wallet->executeForCalibratedMonth('2026-07', 10000);

        $this->assertSame(0, LedgerEntry::query()->where('source_type', 'ns_transfer')->count());
    }

    public function testInvalidArgumentsRejected(): void
    {
        try {
            $this->wallet->executeForCalibratedMonth('2026-13', 10000);
            $this->fail('Ожидался DomainException на месяц 13');
        } catch (\DomainException) {
        }
        $this->expectException(\DomainException::class);
        $this->wallet->executeForCalibratedMonth('2026-07', 10001);
    }

    public function testContractBindingExecutes(): void
    {
        $m = $this->memberWithNs(1000);
        app(NsToOsTransfer::class)->executeForCalibratedMonth('2026-07', 10000);
        $this->assertSame(1000, $this->account($m->id)->os_available_cents);
    }

    public function testWithinMonthReversalIsNettedNotTransferred(): void
    {
        // MF-W5-1 (контракт-чек W2+ №2): сторно НС текущего месяца штампует ns_month на
        // DR-ноге; перевод НС→ОС обязан НЕТТИТЬ CR−DR по месяцу, иначе перенёс бы уже
        // сторнированные деньги. Плоский кап min(monthCents, ns_cents) маскирует баг при
        // ОДНОМ месяце — берём ДВА: сторно июля не должно красть из августовского баланса.
        $m = $this->memberWithNs(1000, '2026-07');                                   // июль CR 1000
        $this->wallet->credit($m->id, 'ns', 1000, "v2:t:aug:{$m->id}", accrualMonth: '2026-08'); // авг CR 1000

        // Сторно 300 июльского начисления (путь T12 reverseBonusCredit НС с accrualMonth).
        $res = $this->wallet->reverseBonusCredit(
            memberId: $m->id, subaccount: 'ns', amountCents: 300,
            idempotencyKey: "v2:t:rev-jul:{$m->id}", sourceType: 'structural', accrualMonth: '2026-07',
        );
        $this->assertSame(300, $res['debited']);
        $this->assertSame(0, $res['clawback']);
        $this->assertSame(1700, $this->account($m->id)->ns_cents);

        // Июль: переносится нетто 1000−300 = 700 (НЕ 1000).
        $this->wallet->executeForCalibratedMonth('2026-07', 10000);
        $a = $this->account($m->id);
        $this->assertSame(700, $a->os_available_cents, 'июль: нетто CR−DR = 700, сторнированное не уносится');
        $this->assertSame(1000, $a->ns_cents, 'август не тронут (сторно июля не украло из его баланса)');

        // Август переносится целиком под своим фактором.
        $this->wallet->executeForCalibratedMonth('2026-08', 10000);
        $a = $this->account($m->id);
        $this->assertSame(1700, $a->os_available_cents);
        $this->assertSame(0, $a->ns_cents);
    }
}
