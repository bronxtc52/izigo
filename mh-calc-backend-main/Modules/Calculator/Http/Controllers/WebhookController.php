<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Services\PaymentService;
use RuntimeException;

/**
 * Публичные webhook'и платёжных шлюзов (Фаза 4). БЕЗ telegram.auth — аутентификация
 * по подписи внутри драйвера шлюза. Невалидная подпись/несоответствие → 400.
 *
 * @group Webhooks
 */
class WebhookController
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function walletPay(Request $request): JsonResponse
    {
        try {
            return response()->json($this->payments->handleWebhook($request));
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
