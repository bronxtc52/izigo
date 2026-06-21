<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\CabinetService;
use Modules\Calculator\Services\WalletService;
use Modules\Calculator\Services\WithdrawalService;
use RuntimeException;

/**
 * Кабинет партнёра (Telegram Mini App): профиль/реф-ссылка, доход, ранги, дерево,
 * активация. Авторизация — middleware telegram.auth; участник лежит в request('member').
 *
 * @group Cabinet
 */
class CabinetController
{
    public function __construct(
        private readonly CabinetService $service,
        private readonly WalletService $wallet,
        private readonly WithdrawalService $withdrawals,
    ) {
    }

    public function me(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->service->profile($this->member($request)));
    }

    public function dashboard(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->service->dashboard($this->member($request)));
    }

    public function rankProgress(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->service->rankProgress($this->member($request)));
    }

    public function teamTree(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->service->teamTree($this->member($request)));
    }

    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => 'required|integer|exists:calculator_packages,id',
            'idempotency_key' => 'nullable|string|max:255',
        ]);

        return $this->guarded(fn () => $this->service->activatePackage(
            $this->member($request),
            (int) $validated['package_id'],
            $validated['idempotency_key'] ?? null,
        ));
    }

    public function wallet(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->wallet->balance($this->member($request)));
    }

    public function walletTransactions(Request $request): JsonResponse
    {
        $cursor = $request->query('cursor');

        return $this->guarded(fn () => $this->wallet->transactions(
            $this->member($request),
            $cursor !== null ? (int) $cursor : null,
        ));
    }

    public function withdrawals(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->withdrawals->listForMember($this->member($request)));
    }

    public function createWithdrawal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|string|max:32',
            'payout_details' => 'required|string|max:1000',
        ]);

        return $this->guarded(fn () => $this->withdrawals->create(
            $this->member($request),
            (string) $validated['amount'],
            (string) $validated['payout_details'],
        ));
    }

    /** Текущий участник, резолвленный telegram.auth. */
    private function member(Request $request): Member
    {
        return $request->attributes->get('member');
    }

    /** Единый формат успеха + аккуратный 404 при доменной ошибке. */
    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }
    }
}
