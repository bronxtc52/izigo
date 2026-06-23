<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\Notification\BroadcastService;
use RuntimeException;

/**
 * C1 (Block C): админ-рассылки (web.admin + calculator.role:owner,support — RBAC на
 * роутах). Тонкий контроллер: preview (dry-run охвата) + send (постановка в outbox).
 * Текст хранится сырьём, нормализуется в Telegram-HTML на выходе (BroadcastService).
 *
 * @group Notifications
 */
class BroadcastAdminController
{
    public function __construct(private readonly BroadcastService $service)
    {
    }

    /** Dry-run охвата сегмента БЕЗ записи. */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, false);

        return $this->guarded(fn () => $this->service->preview($data['segment_type'], $data['segment_value']));
    }

    /** Отправить рассылку (ставит уведомления пачкой в outbox). */
    public function send(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, true);

        return $this->guarded(fn () => $this->service->dispatch(
            $this->viewer($request)->id,
            $data['segment_type'],
            $data['segment_value'],
            (string) $data['body'],
        ));
    }

    /**
     * @return array{segment_type:string,segment_value:?string,body:?string}
     */
    private function validatePayload(Request $request, bool $requireBody): array
    {
        $rules = [
            'segment_type' => 'required|string|in:all,by_status,by_rank',
            'segment_value' => 'nullable|string|max:64',
        ];
        if ($requireBody) {
            $rules['body'] = 'required|string|max:4000';
        }
        $validated = $request->validate($rules);

        return [
            'segment_type' => $validated['segment_type'],
            'segment_value' => $validated['segment_value'] ?? null,
            'body' => $validated['body'] ?? null,
        ];
    }

    private function viewer(Request $request): Member
    {
        return $request->attributes->get('member');
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Не найдено'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
