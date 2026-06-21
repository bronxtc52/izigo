<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Services\CabinetService;
use RuntimeException;

/**
 * Кабинет партнёра (web + Mini App): профиль/реф-ссылка, доход, ранги, дерево, активация.
 * Авторизация — middleware calculator.validate.token; участник резолвится из токена.
 *
 * @group Cabinet
 */
class CabinetController
{
    public function __construct(private readonly CabinetService $service)
    {
    }

    public function me(): JsonResponse
    {
        return $this->guarded(fn () => $this->service->profile($this->service->currentMember()));
    }

    public function dashboard(): JsonResponse
    {
        return $this->guarded(fn () => $this->service->dashboard($this->service->currentMember()));
    }

    public function rankProgress(): JsonResponse
    {
        return $this->guarded(fn () => $this->service->rankProgress($this->service->currentMember()));
    }

    public function teamTree(): JsonResponse
    {
        return $this->guarded(fn () => $this->service->teamTree($this->service->currentMember()));
    }

    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => 'required|integer|exists:calculator_packages,id',
            'idempotency_key' => 'nullable|string|max:255',
        ]);

        return $this->guarded(fn () => $this->service->activatePackage(
            $this->service->currentMember(),
            (int) $validated['package_id'],
            $validated['idempotency_key'] ?? null,
        ));
    }

    /** Единый формат успеха + аккуратный 404, если участник не заведён. */
    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }
    }
}
