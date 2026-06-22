<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Ticket;
use Modules\Calculator\Models\TicketMessage;
use Modules\Calculator\Services\Helpdesk\HelpdeskService;

/**
 * C2 (Block C): тикеты поддержки — сторона ПАРТНЁРА (cabinet, telegram.auth).
 *
 * Скоуп строго по текущему участнику (member из request): партнёр видит/пишет ТОЛЬКО
 * свои тикеты. Чужой тикет → 404 (не раскрываем существование) — это реальная защита
 * на бэкенде, не только скрытие в UI. Транспорт чтения треда — polling (since-курсор).
 *
 * @group Helpdesk
 */
class TicketController
{
    public function __construct(private readonly HelpdeskService $helpdesk)
    {
    }

    /** Список СВОИХ тикетов (по свежести сообщений). */
    public function index(Request $request): JsonResponse
    {
        $items = Ticket::query()
            ->where('member_id', $this->member($request)->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Ticket $t) => $this->presentTicket($t))
            ->all();

        return response()->json(['status' => 'success', 'data' => $items]);
    }

    /** Создать СВОЙ тикет + первое сообщение. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:160',
            'body' => 'required|string|max:4000',
        ]);

        $ticket = $this->helpdesk->openTicket($this->member($request), $data['subject'], $data['body']);

        return response()->json(['status' => 'success', 'data' => $this->presentTicket($ticket)], 201);
    }

    /** Свой тикет + полный тред (чужой → 404). */
    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = $this->ownTicketOrFail($request, $id);
        $messages = $ticket->messages()->orderBy('id')->get()
            ->map(fn (TicketMessage $m) => $this->presentMessage($m))->all();

        return response()->json([
            'status' => 'success',
            'data' => ['ticket' => $this->presentTicket($ticket), 'messages' => $messages],
        ]);
    }

    /** Добавить сообщение в СВОЙ тикет (чужой → 404). */
    public function postMessage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:4000']);
        $ticket = $this->ownTicketOrFail($request, $id);

        $message = $this->helpdesk->postMemberMessage($ticket, $this->member($request), $data['body']);

        return response()->json(['status' => 'success', 'data' => $this->presentMessage($message)], 201);
    }

    /**
     * Polling: новые сообщения СВОЕГО тикета после курсора since (id последнего
     * полученного). Чужой тикет → 404. Возвращает только сообщения с id > since.
     */
    public function pollMessages(Request $request, int $id): JsonResponse
    {
        $ticket = $this->ownTicketOrFail($request, $id);
        $since = (int) $request->query('since', '0');

        $messages = $ticket->messages()
            ->where('id', '>', $since)
            ->orderBy('id')
            ->get()
            ->map(fn (TicketMessage $m) => $this->presentMessage($m))
            ->all();

        return response()->json([
            'status' => 'success',
            'data' => [
                'status' => $ticket->status,
                'messages' => $messages,
                'cursor' => $messages === [] ? $since : end($messages)['id'],
            ],
        ]);
    }

    /**
     * Тикет текущего участника или 404. Гарантия, что партнёр трогает ТОЛЬКО свой
     * тикет — backend-скоуп, а не только UI.
     */
    private function ownTicketOrFail(Request $request, int $id): Ticket
    {
        return Ticket::query()
            ->where('id', $id)
            ->where('member_id', $this->member($request)->id)
            ->firstOrFail();
    }

    private function presentTicket(Ticket $t): array
    {
        return [
            'id' => $t->id,
            'subject' => $t->subject,
            'status' => $t->status,
            'assigned_to' => $t->assigned_to,
            'last_message_at' => $t->last_message_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    private function presentMessage(TicketMessage $m): array
    {
        return [
            'id' => $m->id,
            'ticket_id' => $m->ticket_id,
            'author_role' => $m->author_role,
            'body' => $m->body,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('member');
    }
}
