<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\CabinetService;
use Modules\Calculator\Services\Telegram\MiniAppAuth;

/**
 * Telegram Mini App: тот же кабинет, но авторизация по initData (HMAC), участник
 * резолвится по telegram_id. Переиспользует CabinetService.
 *
 * @group MiniApp
 */
class MiniAppController
{
    public function __construct(
        private readonly MiniAppAuth $auth,
        private readonly CabinetService $cabinet,
    ) {
    }

    public function me(Request $request): JsonResponse
    {
        return $this->guarded($request, fn (Member $m) => $this->cabinet->profile($m));
    }

    public function dashboard(Request $request): JsonResponse
    {
        return $this->guarded($request, fn (Member $m) => $this->cabinet->dashboard($m));
    }

    public function rankProgress(Request $request): JsonResponse
    {
        return $this->guarded($request, fn (Member $m) => $this->cabinet->rankProgress($m));
    }

    public function teamTree(Request $request): JsonResponse
    {
        return $this->guarded($request, fn (Member $m) => $this->cabinet->teamTree($m));
    }

    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => 'required|integer|exists:calculator_packages,id',
            'idempotency_key' => 'nullable|string|max:255',
        ]);

        return $this->guarded($request, fn (Member $m) => $this->cabinet->activatePackage(
            $m,
            (int) $validated['package_id'],
            $validated['idempotency_key'] ?? null,
        ));
    }

    /** Валидирует initData, резолвит участника и отдаёт результат; 401 при неверной подписи. */
    private function guarded(Request $request, callable $fn): JsonResponse
    {
        try {
            $member = $this->auth->resolveMember((string) $request->header('X-Telegram-Init-Data', ''));
            return response()->json(['status' => 'success', 'data' => $fn($member)]);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 401);
        }
    }
}
