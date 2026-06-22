<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\NotificationInbox;
use RuntimeException;

/**
 * C1 (Block C): inbox партнёра (cabinet, telegram.auth). Партнёр видит и трогает
 * ТОЛЬКО свои уведомления (member из request). Тонкий контроллер — без сервиса:
 * простое чтение/отметка прочтения своего inbox.
 *
 * @group Notifications
 */
class NotificationController
{
    /** Список своих уведомлений (новые сверху). */
    public function index(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request) {
            $memberId = $this->member($request)->id;

            return NotificationInbox::query()
                ->where('member_id', $memberId)
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (NotificationInbox $n) => [
                    'id' => $n->id,
                    'kind' => $n->kind,
                    'title' => $n->title,
                    'body' => $n->body,
                    'data' => $n->data,
                    'read' => $n->read_at !== null,
                    'read_at' => $n->read_at?->toIso8601String(),
                    'created_at' => $n->created_at?->toIso8601String(),
                ])
                ->all();
        });
    }

    /** Кол-во непрочитанных своих уведомлений (для бейджа). */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request) {
            $count = NotificationInbox::query()
                ->where('member_id', $this->member($request)->id)
                ->whereNull('read_at')
                ->count();

            return ['unread' => $count];
        });
    }

    /** Отметить своё уведомление прочитанным (чужое → 404, не раскрываем существование). */
    public function markRead(Request $request, int $id): JsonResponse
    {
        return $this->guarded(function () use ($request, $id) {
            $n = NotificationInbox::query()
                ->where('id', $id)
                ->where('member_id', $this->member($request)->id)
                ->firstOrFail();
            if ($n->read_at === null) {
                $n->read_at = now();
                $n->save();
            }

            return ['id' => $n->id, 'read' => true];
        });
    }

    /** Отметить все свои уведомления прочитанными. */
    public function markAllRead(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request) {
            $updated = NotificationInbox::query()
                ->where('member_id', $this->member($request)->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            return ['marked' => $updated];
        });
    }

    private function member(Request $request): Member
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
