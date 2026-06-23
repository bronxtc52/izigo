<?php

namespace Modules\Calculator\Services\Helpdesk;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Ticket;
use Modules\Calculator\Models\TicketMessage;
use Modules\Calculator\Services\Notification\NotificationService;
use Throwable;

/**
 * C2 (Block C): helpdesk — создание тикетов и сообщений + уведомления через C1.
 *
 * Границы:
 *  - Пуши идут ТОЛЬКО через NotificationService (контракт C1), best-effort: ошибка
 *    доставки/постановки НЕ ломает создание тикета или ответа (как payout-хук C1).
 *  - Свой минимальный Telegram-HTML строится здесь (escapeHtml), TelegramNotifications
 *    (C1) не трогаем.
 *  - Операторы = участники с ролью owner или support; список берётся из members/roles.
 */
class HelpdeskService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Создать тикет партнёра + первое сообщение (member) в одной транзакции.
     * После commit — best-effort уведомление операторам.
     */
    public function openTicket(Member $member, string $subject, string $body): Ticket
    {
        [$ticket, $firstMessage] = DB::transaction(function () use ($member, $subject, $body) {
            $ticket = Ticket::create([
                'member_id' => $member->id,
                'subject' => $subject,
                'status' => Ticket::STATUS_OPEN,
                'last_message_at' => now(),
            ]);

            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'author_member_id' => $member->id,
                'author_role' => TicketMessage::ROLE_MEMBER,
                'body' => $body,
            ]);

            return [$ticket->fresh(), $message];
        });

        $this->notifyOperatorsNewMessage($ticket, $firstMessage, $member, true);

        return $ticket;
    }

    /** Сообщение партнёра в свой тикет. После commit — пуш операторам (best-effort). */
    public function postMemberMessage(Ticket $ticket, Member $member, string $body): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $member, $body) {
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'author_member_id' => $member->id,
                'author_role' => TicketMessage::ROLE_MEMBER,
                'body' => $body,
            ]);
            // Ответ партнёра по resolved/closed тикету снова открывает обсуждение.
            $ticket->last_message_at = now();
            if (in_array($ticket->status, [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true)) {
                $ticket->status = Ticket::STATUS_OPEN;
            }
            $ticket->save();

            return $message;
        });

        $this->notifyOperatorsNewMessage($ticket, $message, $member, false);

        return $message;
    }

    /** Ответ оператора в тикет. После commit — пуш автору тикета (best-effort). */
    public function postOperatorReply(Ticket $ticket, Member $operator, string $body): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $operator, $body) {
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'author_member_id' => $operator->id,
                'author_role' => TicketMessage::ROLE_OPERATOR,
                'body' => $body,
            ]);
            $ticket->last_message_at = now();
            // Первый ответ по open-тикету переводит в работу.
            if ($ticket->status === Ticket::STATUS_OPEN) {
                $ticket->status = Ticket::STATUS_IN_PROGRESS;
            }
            $ticket->save();

            return $message;
        });

        $this->notifyMemberReply($ticket, $message);

        return $message;
    }

    /**
     * Best-effort уведомление автора тикета об ответе ОПЕРАТОРА (через C1).
     * dedupKey идемпотентен по id сообщения.
     */
    private function notifyMemberReply(Ticket $ticket, TicketMessage $message): void
    {
        try {
            $html = '💬 Новый ответ по обращению <b>' . $this->escapeHtml($ticket->subject) . "</b>:\n"
                . $this->escapeHtml($this->preview($message->body));
            $this->notifications->enqueueToMember(
                (int) $ticket->member_id,
                'ticket.reply',
                $html,
                'Поддержка',
                'ticket.reply:msg:' . $message->id,
                ['ticket_id' => $ticket->id, 'message_id' => $message->id],
            );
        } catch (Throwable $e) {
            // Best-effort: доставка не критична для ответа. Токен бота не логируем.
        }
    }

    /**
     * Best-effort уведомление операторов (owner+support) о новом тикете/сообщении
     * УЧАСТНИКА (через C1). dedupKey идемпотентен по id сообщения.
     */
    private function notifyOperatorsNewMessage(Ticket $ticket, TicketMessage $message, Member $author, bool $isNew): void
    {
        try {
            $operatorIds = $this->operatorMemberIds();
            // Сам автор-оператор (если у партнёра вдруг есть роль) не уведомляется о своём.
            $operatorIds = array_values(array_filter($operatorIds, static fn ($id) => (int) $id !== (int) $author->id));
            if ($operatorIds === []) {
                return;
            }

            $verb = $isNew ? 'Новое обращение' : 'Новое сообщение в обращении';
            $html = '🆘 ' . $verb . ' <b>' . $this->escapeHtml($ticket->subject) . "</b>:\n"
                . $this->escapeHtml($this->preview($message->body));

            $this->notifications->enqueueForMembers(
                $operatorIds,
                'ticket.member',
                $html,
                'Тикет поддержки',
                'ticket.member:msg:' . $message->id,
                ['ticket_id' => $ticket->id, 'message_id' => $message->id],
            );
        } catch (Throwable $e) {
            // Best-effort: доставка не критична для тикета.
        }
    }

    /** Участники-операторы (роль owner или support). Возвращает их member_id. */
    public function operatorMemberIds(): array
    {
        return Member::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['owner', 'support']))
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /** Короткий превью-обрезок тела для пуша (по границе, не рвём посередине слова грубо). */
    private function preview(string $body): string
    {
        $body = trim($body);
        if (mb_strlen($body) <= 200) {
            return $body;
        }

        return mb_substr($body, 0, 200) . '…';
    }

    /** Минимальный безопасный Telegram-HTML: только экранирование спецсимволов. */
    private function escapeHtml(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
