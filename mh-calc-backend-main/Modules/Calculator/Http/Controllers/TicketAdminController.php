<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Ticket;
use Modules\Calculator\Models\TicketMessage;
use Modules\Calculator\Services\Helpdesk\HelpdeskService;

/**
 * C2 (Block C): тикеты поддержки — сторона ОПЕРАТОРА (web.admin + owner,support).
 *
 * Оператор видит ВСЕ тикеты (это его роль), отвечает (author_role=operator), меняет
 * статус и берёт тикет на себя/назначает. RBAC закрыт на роутах (calculator.role:
 * owner,support); finance/leader/без-auth не проходят. Ответ оператора → пуш автору
 * тикета через C1 (внутри сервиса, best-effort).
 *
 * @group Helpdesk
 */
class TicketAdminController
{
    public function __construct(private readonly HelpdeskService $helpdesk)
    {
    }

    /** Очередь тикетов с фильтром по status и assigned (mine|<member_id>|unassigned). */
    public function index(Request $request): JsonResponse
    {
        $query = Ticket::query()->with('member:id,name,telegram_username');

        $status = (string) $request->query('status', '');
        if ($status !== '' && in_array($status, Ticket::STATUSES, true)) {
            $query->where('status', $status);
        }

        $assigned = (string) $request->query('assigned', '');
        if ($assigned === 'mine') {
            $query->where('assigned_to', $this->operator($request)->id);
        } elseif ($assigned === 'unassigned') {
            $query->whereNull('assigned_to');
        } elseif ($assigned !== '' && ctype_digit($assigned)) {
            $query->where('assigned_to', (int) $assigned);
        }

        $items = $query
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END")
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Ticket $t) => $this->presentTicket($t))
            ->all();

        return response()->json(['status' => 'success', 'data' => $items]);
    }

    /** Тикет + полный тред (оператор видит любой). */
    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::with('member:id,name,telegram_username')->findOrFail($id);
        $messages = $ticket->messages()->orderBy('id')->get()
            ->map(fn (TicketMessage $m) => $this->presentMessage($m))->all();

        return response()->json([
            'status' => 'success',
            'data' => ['ticket' => $this->presentTicket($ticket), 'messages' => $messages],
        ]);
    }

    /** Ответ оператора (author_role=operator) → пуш автору тикета через C1. */
    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:4000']);
        $ticket = Ticket::findOrFail($id);

        $message = $this->helpdesk->postOperatorReply($ticket, $this->operator($request), $data['body']);

        return response()->json(['status' => 'success', 'data' => $this->presentMessage($message)], 201);
    }

    /** Сменить статус тикета. */
    public function setStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => 'required|string|in:open,in_progress,resolved,closed']);
        $ticket = Ticket::findOrFail($id);
        $ticket->status = $data['status'];
        $ticket->save();

        return response()->json(['status' => 'success', 'data' => $this->presentTicket($ticket)]);
    }

    /** Взять на себя / назначить оператора. assigned_to=null снимает назначение. */
    public function assign(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['assigned_to' => 'nullable|integer']);
        $ticket = Ticket::findOrFail($id);

        // Ключ assigned_to передан (даже =null) → явное назначение/снятие; отсутствует
        // → «взять на себя».
        $assignee = $request->exists('assigned_to')
            ? ($data['assigned_to'] ?? null)
            : $this->operator($request)->id;

        // Назначать можно ТОЛЬКО на оператора (owner/support) — иначе тикет «повиснет»
        // на том, кто его не видит в очереди; несуществующий id дал бы FK-violation 500.
        if ($assignee !== null && !in_array((int) $assignee, $this->helpdesk->operatorMemberIds(), true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Назначить можно только на оператора (owner/support)',
            ], 422);
        }

        $ticket->assigned_to = $assignee;
        if ($assignee !== null && $ticket->status === Ticket::STATUS_OPEN) {
            $ticket->status = Ticket::STATUS_IN_PROGRESS;
        }
        $ticket->save();

        return response()->json(['status' => 'success', 'data' => $this->presentTicket($ticket)]);
    }

    private function presentTicket(Ticket $t): array
    {
        return [
            'id' => $t->id,
            'member_id' => $t->member_id,
            'member_name' => $t->member?->name,
            'member_username' => $t->member?->telegram_username,
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
            'author_member_id' => $m->author_member_id,
            'body' => $m->body,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    private function operator(Request $request): Member
    {
        return $request->attributes->get('member');
    }
}
