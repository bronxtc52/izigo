<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\KycService;
use Modules\Calculator\Services\OrderService;
use Modules\Calculator\Services\ProductAdminService;
use RuntimeException;

/**
 * Управление каталогом, заказами и KYC из админ-портала (Фаза 4). RBAC-гейты — на уровне
 * маршрутов (товары/заказы — owner,support; KYC — owner,finance).
 *
 * @group Commerce Admin
 */
class CommerceAdminController
{
    public function __construct(
        private readonly ProductAdminService $products,
        private readonly OrderService $orders,
        private readonly KycService $kyc,
    ) {
    }

    public function products(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->products->listAll());
    }

    public function createProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price_usdt_cents' => 'required|integer|min:0',
            'pv' => 'nullable|integer|min:0',
            'package_id' => 'required|integer|exists:calculator_packages,id',
            'sku' => 'required|string|max:64|unique:products,sku',
            'is_active' => 'nullable|boolean',
            'sort' => 'nullable|integer',
            'stock' => 'nullable|integer|min:0',
        ]);

        return $this->guarded(fn () => $this->products->create($data));
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price_usdt_cents' => 'sometimes|required|integer|min:0',
            'pv' => 'sometimes|required|integer|min:0',
            'package_id' => 'sometimes|required|integer|exists:calculator_packages,id',
            'sku' => "sometimes|required|string|max:64|unique:products,sku,{$id}",
            'is_active' => 'sometimes|boolean',
            'sort' => 'sometimes|integer',
            'stock' => 'nullable|integer|min:0',
        ]);

        return $this->guarded(fn () => $this->products->update($id, $data));
    }

    public function deleteProduct(Request $request, int $id): JsonResponse
    {
        return $this->guarded(fn () => $this->products->archive($id));
    }

    public function orders(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->orders->listForAdmin($request->query('status')));
    }

    public function updateOrderStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|max:24',
            'tracking_no' => 'nullable|string|max:128',
        ]);

        return $this->guarded(fn () => $this->orders->setStatus(
            $id,
            (string) $validated['status'],
            $validated['tracking_no'] ?? null,
        ));
    }

    public function kyc(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->kyc->listForAdmin($request->query('status')));
    }

    public function reviewKyc(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'approve' => 'required|boolean',
            'reason' => 'nullable|string|max:1000',
        ]);

        /** @var Member $viewer */
        $viewer = $request->attributes->get('member');

        return $this->guarded(fn () => $this->kyc->review(
            $id,
            $viewer,
            (bool) $validated['approve'],
            $validated['reason'] ?? null,
        ));
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
