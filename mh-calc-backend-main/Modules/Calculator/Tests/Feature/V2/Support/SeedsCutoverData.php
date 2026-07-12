<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Models\WithdrawalRequest;
use Modules\Calculator\Services\LedgerService;

/**
 * T15: хелперы cutover-тестов — участники, main-баланс (через LedgerService::deposit,
 * даёт сбалансированный ledger + консистентный кэш member_wallets), тариф Bronze.
 */
trait SeedsCutoverData
{
    /**
     * Участник дерева. Первый (без $parent) — корень (parent_id NULL, единственный —
     * ограничение members_single_root); остальные вешаются под указанного родителя.
     */
    protected function seedMember(int $tg, ?Member $parent = null): Member
    {
        $path = $parent === null ? (string) $tg : $parent->path . '.' . $tg;

        return Member::create([
            'telegram_id' => $tg,
            'name' => "m{$tg}",
            'ref_code' => 'RC' . str_pad((string) $tg, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'path' => $path,
            'sponsor_id' => $parent?->id,
            'parent_id' => $parent?->id,
        ]);
    }

    /** Пополнить доступный баланс (Dr company_deposits / Cr member_available + кэш). */
    protected function deposit(int $memberId, int $cents): void
    {
        app(LedgerService::class)->deposit($memberId, $cents, "seed:deposit:m{$memberId}");
    }

    /** Поставить часть баланса в held: заявка на вывод «в полёте» (available → held). */
    protected function hold(int $memberId, int $cents): void
    {
        $w = WithdrawalRequest::create([
            'member_id' => $memberId,
            'amount_cents' => $cents,
            'payout_details' => 'ton:seed',
            'status' => WithdrawalRequest::STATUS_REQUESTED,
            'requested_at' => now(),
        ]);
        DB::transaction(fn () => app(LedgerService::class)->hold($w));
    }

    /** Тариф Bronze в исходном состоянии 90 PV / 90 USDT (до правки cutover). */
    protected function seedBronze(): Product
    {
        return Product::query()->create([
            'name' => 'Bronze',
            'sku' => 'TARIFF-BRONZE',
            'package_id' => 1,
            'pv' => 90,
            'price_usdt_cents' => 9000,
            'is_active' => true,
            'sort' => 1,
        ]);
    }
}
