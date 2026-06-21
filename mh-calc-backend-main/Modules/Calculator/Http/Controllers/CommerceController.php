<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\AutoshipService;
use Modules\Calculator\Services\CatalogService;
use Modules\Calculator\Services\KycService;
use Modules\Calculator\Services\OrderService;
use Modules\Calculator\Services\PaymentService;
use RuntimeException;

/**
 * Commerce в кабинете партнёра (Фаза 4): витрина каталога, заказы, оплата, пополнение,
 * autoship. Авторизация — telegram.auth; участник в request('member').
 *
 * @group Commerce
 */
class CommerceController
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly AutoshipService $autoship,
        private readonly KycService $kyc,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->catalog->listActive());
    }

    public function orders(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->orders->listForMember($this->member($request)));
    }

    public function order(Request $request, int $id): JsonResponse
    {
        return $this->guarded(fn () => $this->orders->getForMember($this->member($request), $id));
    }

    public function createOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'qty' => 'nullable|integer|min:1',
            'idempotency_key' => 'nullable|string|max:255',
        ]);

        return $this->guarded(fn () => $this->orders->create(
            $this->member($request),
            (int) $validated['product_id'],
            (int) ($validated['qty'] ?? 1),
            $validated['idempotency_key'] ?? null,
        ));
    }

    public function payOrder(Request $request, int $id): JsonResponse
    {
        return $this->guarded(fn () => $this->payments->startOrderPayment($this->member($request), $id));
    }

    public function checkPayment(Request $request, int $id): JsonResponse
    {
        return $this->guarded(fn () => $this->payments->checkForMember($this->member($request), $id));
    }

    public function topup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Верхняя граница против абьюзных/мусорных инвойсов (1 USDT = 100 центов).
            'amount_cents' => 'required|integer|min:1|max:100000000',
        ]);

        return $this->guarded(fn () => $this->payments->startTopup(
            $this->member($request),
            (int) $validated['amount_cents'],
        ));
    }

    public function autoshipList(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->autoship->listForMember($this->member($request)));
    }

    public function autoshipCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'interval_days' => 'required|integer|min:1|max:365',
        ]);

        return $this->guarded(fn () => $this->autoship->create(
            $this->member($request),
            (int) $validated['product_id'],
            (int) $validated['interval_days'],
        ));
    }

    public function autoshipUpdate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|in:pause,resume,cancel',
        ]);

        return $this->guarded(fn () => $this->autoship->setState(
            $this->member($request),
            $id,
            (string) $validated['action'],
        ));
    }

    public function kycStatus(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->kyc->statusFor($this->member($request)));
    }

    public function kycSubmit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'documents' => 'required|array',
        ]);

        return $this->guarded(fn () => $this->kyc->submit(
            $this->member($request),
            (array) $validated['documents'],
        ));
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('member');
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }
    }
}
