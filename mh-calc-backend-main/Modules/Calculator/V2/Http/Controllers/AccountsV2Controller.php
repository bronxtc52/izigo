<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\OrderService;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;
use Modules\Calculator\V2\Services\Wallet\Exceptions\ReservationConflictException;
use Modules\Calculator\V2\Services\Wallet\OrderAccountPaymentService;
use RuntimeException;

/**
 * mh-full-plan T02: счета ОС/НС/БС — cabinet (балансы, лоты, история, оплата заказа
 * со счетов) + admin read-only (для T13). Формат денег: центы integer + строка decimal
 * (образец WalletService::centsToDecimal). IDOR-скоуп: заказ/данные резолвятся через
 * аутентифицированного участника (amendments nice-to-have #2).
 */
class AccountsV2Controller
{
    private const V2_MEMBER_ACCOUNTS = [
        LedgerPostingV2Service::ACC_OS_AVAILABLE,
        LedgerPostingV2Service::ACC_OS_HELD,
        LedgerPostingV2Service::ACC_NS,
        LedgerPostingV2Service::ACC_BS_AVAILABLE,
        LedgerPostingV2Service::ACC_BS_HELD,
    ];

    public function __construct(
        private readonly OrderAccountPaymentService $orderPayment,
        private readonly OrderService $orders,
        private readonly ActivationService $activation,
    ) {
    }

    // ------------------------------------------------------------------
    // Cabinet (telegram.auth + feature.flag:mh_plan_v2_miniapp)
    // ------------------------------------------------------------------

    /** Балансы субсчетов + ближайшие сгорания лотов. */
    public function accounts(Request $request): JsonResponse
    {
        $member = $this->member($request);

        return $this->guarded(fn () => $this->accountsPayload($member->id));
    }

    /** Активные лоты участника (остатки и сроки сгорания). */
    public function lots(Request $request): JsonResponse
    {
        $member = $this->member($request);

        return $this->guarded(fn () => $this->lotsPayload($member->id));
    }

    /** История движений по V2-субсчетам (курсорная пагинация по id проводки). */
    public function history(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $beforeId = $request->query('before_id') !== null ? (int) $request->query('before_id') : null;
        $limit = max(1, min((int) $request->query('limit', 20), 100));

        return $this->guarded(function () use ($member, $beforeId, $limit) {
            $entries = LedgerEntry::query()
                ->where('member_id', $member->id)
                ->whereIn('account_type', self::V2_MEMBER_ACCOUNTS)
                ->when($beforeId !== null, fn ($q) => $q->where('id', '<', $beforeId))
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get(['id', 'account_type', 'direction', 'amount_cents', 'source_type', 'source_id', 'created_at']);

            $hasMore = $entries->count() > $limit;
            $page = $entries->take($limit);

            return [
                'items' => $page->map(fn (LedgerEntry $e) => [
                    'id' => $e->id,
                    'account_type' => $e->account_type,
                    // Знак с точки зрения счёта партнёра: credit = приход.
                    'amount_cents' => $e->direction === 'credit' ? $e->amount_cents : -$e->amount_cents,
                    'amount' => $this->centsToDecimal(
                        $e->direction === 'credit' ? $e->amount_cents : -$e->amount_cents,
                    ),
                    'source_type' => $e->source_type,
                    'source_id' => $e->source_id,
                    'created_at' => $e->created_at?->toIso8601String(),
                ])->values()->all(),
                'next_cursor' => $hasMore ? $page->last()->id : null,
            ];
        });
    }

    /**
     * Оплата заказа со счетов: резерв {os_cents, bs_cents}. ОС ≤70% стоимости, БС — без
     * лимита. Полная оплата со счетов (remainder = 0) сразу проводит заказ в paid
     * (markPaid → capture → активация) без TON-инвойса.
     */
    public function accountPayment(Request $request, int $orderId): JsonResponse
    {
        $member = $this->member($request);
        $validated = $request->validate([
            'os_cents' => 'nullable|integer|min:0',
            'bs_cents' => 'nullable|integer|min:0',
        ]);
        $osCents = (int) ($validated['os_cents'] ?? 0);
        $bsCents = (int) ($validated['bs_cents'] ?? 0);

        return $this->guarded(function () use ($member, $orderId, $osCents, $bsCents) {
            // IDOR-скоуп: заказ строго текущего участника.
            $order = Order::query()
                ->where('member_id', $member->id)
                ->where('id', $orderId)
                ->firstOrFail();

            if ($osCents + $bsCents === (int) $order->total_usdt_cents) {
                // Полная оплата со счетов — резерв + markPaid (capture + активация) одной
                // транзакцией, без TON-инвойса. Advisory-lock активаций берём ПЕРВЫМ
                // действием, ДО любых ledger-записей резерва (жёсткая рамка проекта).
                $result = DB::transaction(function () use ($order, $osCents, $bsCents) {
                    $this->activation->acquireActivationLock();
                    $result = $this->orderPayment->reserve($order, $osCents, $bsCents);
                    $this->orders->markPaid($order->id);

                    return $result;
                });
                $result['paid'] = true;
            } else {
                $result = $this->orderPayment->reserve($order, $osCents, $bsCents);
                $result['paid'] = false;
            }

            $result['os'] = $this->centsToDecimal($result['os_cents']);
            $result['bs'] = $this->centsToDecimal($result['bs_cents']);
            $result['remainder'] = $this->centsToDecimal($result['remainder_cents']);

            return $result;
        });
    }

    /** Отменить резерв счетов по заказу (возврат средств в лоты). */
    public function cancelAccountPayment(Request $request, int $orderId): JsonResponse
    {
        $member = $this->member($request);

        return $this->guarded(function () use ($member, $orderId) {
            Order::query()
                ->where('member_id', $member->id)
                ->where('id', $orderId)
                ->firstOrFail();

            $this->orderPayment->release($orderId);

            return ['released' => true];
        });
    }

    // ------------------------------------------------------------------
    // Admin read-only (web.admin + feature.flag:mh_plan_v2_admin + role owner,finance)
    // ------------------------------------------------------------------

    public function adminMemberAccounts(int $memberId): JsonResponse
    {
        return $this->guarded(function () use ($memberId) {
            Member::query()->findOrFail($memberId);

            return $this->accountsPayload($memberId);
        });
    }

    public function adminMemberLots(int $memberId): JsonResponse
    {
        return $this->guarded(function () use ($memberId) {
            Member::query()->findOrFail($memberId);

            return $this->lotsPayload($memberId, includeClosed: true);
        });
    }

    // ------------------------------------------------------------------

    private function accountsPayload(int $memberId): array
    {
        $a = MemberAccountV2::query()->where('member_id', $memberId)->first();

        $upcoming = WalletLotV2::query()
            ->where('member_id', $memberId)
            ->where('status', WalletLotV2::STATUS_ACTIVE)
            ->where('available_cents', '>', 0)
            ->whereNotNull('expires_at')
            ->orderBy('expires_at')->orderBy('id')
            ->limit(3)
            ->get(['account', 'available_cents', 'expires_at'])
            ->map(fn (WalletLotV2 $lot) => [
                'account' => $lot->account,
                'amount_cents' => $lot->available_cents,
                'amount' => $this->centsToDecimal($lot->available_cents),
                'expires_at' => $lot->expires_at?->toIso8601String(),
            ])->all();

        $cents = [
            'os_available_cents' => $a->os_available_cents ?? 0,
            'os_held_cents' => $a->os_held_cents ?? 0,
            'ns_cents' => $a->ns_cents ?? 0,
            'bs_available_cents' => $a->bs_available_cents ?? 0,
            'bs_held_cents' => $a->bs_held_cents ?? 0,
        ];

        return $cents + [
            'os_available' => $this->centsToDecimal($cents['os_available_cents']),
            'os_held' => $this->centsToDecimal($cents['os_held_cents']),
            'ns' => $this->centsToDecimal($cents['ns_cents']),
            'bs_available' => $this->centsToDecimal($cents['bs_available_cents']),
            'bs_held' => $this->centsToDecimal($cents['bs_held_cents']),
            'currency' => 'USD',
            'upcoming_expirations' => $upcoming,
        ];
    }

    private function lotsPayload(int $memberId, bool $includeClosed = false): array
    {
        $lots = WalletLotV2::query()
            ->where('member_id', $memberId)
            ->when(! $includeClosed, fn ($q) => $q
                ->where('status', WalletLotV2::STATUS_ACTIVE)
                ->where('available_cents', '>', 0))
            ->orderByRaw('expires_at ASC NULLS LAST, id ASC')
            ->get()
            ->map(fn (WalletLotV2 $lot) => [
                'id' => $lot->id,
                'account' => $lot->account,
                'amount_cents' => $lot->amount_cents,
                'available_cents' => $lot->available_cents,
                'available' => $this->centsToDecimal($lot->available_cents),
                'earned_at' => $lot->earned_at?->toIso8601String(),
                'expires_at' => $lot->expires_at?->toIso8601String(), // null = не сгорает
                'source_type' => $lot->source_type,
                'origin_lot_id' => $lot->origin_lot_id,
                'status' => $lot->status,
            ])->all();

        return ['items' => $lots];
    }

    private function member(Request $request): Member
    {
        /** @var ?Member $member */
        $member = $request->attributes->get('member');
        if ($member === null) {
            throw new ModelNotFoundException();
        }

        return $member;
    }

    /** Центы → строка decimal "D.CC" без float (знак сохраняется). */
    private function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Не найдено'], 404);
        } catch (ReservationConflictException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        } catch (\DomainException $e) {
            // OsOrderLimitExceeded / InsufficientAccountBalance / прочие доменные отказы.
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
