<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\NotificationOutbox;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * C2 (Block C) — helpdesk (тикеты + чат).
 *
 * Скоуп партнёра закрыт на бэкенде: видит/пишет ТОЛЬКО свои тикеты (чужой → 404).
 * Операторские действия (reply/status/assign) — только owner,support; finance/leader
 * → 403, без auth → 401. Пуши идут через C1 (NotificationService) best-effort:
 * новый тикет/сообщение партнёра → operators; ответ оператора → автор тикета.
 * Polling возвращает только новые сообщения по курсору since.
 */
class HelpdeskTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    // --- Cabinet: свои тикеты ---

    public function testPartnerCanOpenAndListOwnTickets(): void
    {
        [$aData] = $this->registerTg(600, name: 'A');

        $res = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'Не приходит бонус', 'body' => 'Помогите разобраться'],
            $this->tgHeaders($aData))->assertStatus(201);
        $res->assertJsonPath('data.subject', 'Не приходит бонус')
            ->assertJsonPath('data.status', 'open');

        $list = $this->getJson('/api/v1/cabinet/tickets', $this->tgHeaders($aData))->assertOk();
        $this->assertCount(1, $list->json('data'));
    }

    public function testPartnerCanReadOwnThreadAndPostMessage(): void
    {
        [$aData] = $this->registerTg(601, name: 'A');
        $id = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'Вопрос', 'body' => 'Первое сообщение'],
            $this->tgHeaders($aData))->json('data.id');

        $show = $this->getJson("/api/v1/cabinet/tickets/{$id}", $this->tgHeaders($aData))->assertOk();
        $this->assertCount(1, $show->json('data.messages'));
        $this->assertSame('member', $show->json('data.messages.0.author_role'));

        $this->postJson("/api/v1/cabinet/tickets/{$id}/messages",
            ['body' => 'Ещё подробности'], $this->tgHeaders($aData))->assertStatus(201);

        $show2 = $this->getJson("/api/v1/cabinet/tickets/{$id}", $this->tgHeaders($aData))->assertOk();
        $this->assertCount(2, $show2->json('data.messages'));
    }

    // --- Negative: scope партнёра ---

    public function testPartnerSeesOnlyOwnTickets(): void
    {
        [$aData] = $this->registerTg(610, name: 'A');
        [$bData] = $this->registerTg(611, name: 'B');
        $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'A ticket', 'body' => 'x'], $this->tgHeaders($aData))->assertStatus(201);

        $list = $this->getJson('/api/v1/cabinet/tickets', $this->tgHeaders($bData))->assertOk();
        $this->assertCount(0, $list->json('data'));
    }

    public function testPartnerCannotReadOthersTicket(): void
    {
        [$aData] = $this->registerTg(620, name: 'A');
        [$bData] = $this->registerTg(621, name: 'B');
        $aId = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'A ticket', 'body' => 'x'], $this->tgHeaders($aData))->json('data.id');

        $this->getJson("/api/v1/cabinet/tickets/{$aId}", $this->tgHeaders($bData))->assertStatus(404);
        $this->getJson("/api/v1/cabinet/tickets/{$aId}/poll", $this->tgHeaders($bData))->assertStatus(404);
    }

    public function testPartnerCannotWriteToOthersTicket(): void
    {
        [$aData] = $this->registerTg(630, name: 'A');
        [$bData] = $this->registerTg(631, name: 'B');
        $aId = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'A ticket', 'body' => 'x'], $this->tgHeaders($aData))->json('data.id');

        $this->postJson("/api/v1/cabinet/tickets/{$aId}/messages",
            ['body' => 'hacked'], $this->tgHeaders($bData))->assertStatus(404);

        // Тред A не изменился (по-прежнему 1 сообщение).
        $this->assertCount(1,
            $this->getJson("/api/v1/cabinet/tickets/{$aId}", $this->tgHeaders($aData))->json('data.messages'));
    }

    public function testCabinetRequiresAuth(): void
    {
        $this->getJson('/api/v1/cabinet/tickets', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
        $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'x', 'body' => 'y'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testStoreValidatesInput(): void
    {
        [$aData] = $this->registerTg(640, name: 'A');
        $this->postJson('/api/v1/cabinet/tickets', ['body' => 'no subject'],
            $this->tgHeaders($aData))->assertStatus(422);
        $this->postJson('/api/v1/cabinet/tickets', ['subject' => 'no body'],
            $this->tgHeaders($aData))->assertStatus(422);
    }

    // --- Polling по курсору ---

    public function testPollReturnsOnlyNewMessagesBySinceCursor(): void
    {
        [$aData] = $this->registerTg(650, name: 'A');
        $id = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'poll', 'body' => 'msg1'], $this->tgHeaders($aData))->json('data.id');

        // since=0 → видим первое сообщение.
        $first = $this->getJson("/api/v1/cabinet/tickets/{$id}/poll?since=0", $this->tgHeaders($aData))->assertOk();
        $this->assertCount(1, $first->json('data.messages'));
        $cursor = $first->json('data.cursor');
        $this->assertGreaterThan(0, $cursor);

        // since=cursor → пусто, пока ничего нового.
        $empty = $this->getJson("/api/v1/cabinet/tickets/{$id}/poll?since={$cursor}", $this->tgHeaders($aData))->assertOk();
        $this->assertCount(0, $empty->json('data.messages'));
        $this->assertSame($cursor, $empty->json('data.cursor'));

        // Добавили сообщение → видно только новое.
        $this->postJson("/api/v1/cabinet/tickets/{$id}/messages",
            ['body' => 'msg2'], $this->tgHeaders($aData))->assertStatus(201);
        $next = $this->getJson("/api/v1/cabinet/tickets/{$id}/poll?since={$cursor}", $this->tgHeaders($aData))->assertOk();
        $this->assertCount(1, $next->json('data.messages'));
        $this->assertSame('msg2', $next->json('data.messages.0.body'));
    }

    // --- C1-уведомления (через outbox) ---

    public function testNewTicketEnqueuesNotificationToOperators(): void
    {
        // Оператор-support должен получить пуш о новом тикете.
        $this->registerTg(660, name: 'Support');
        $this->grantRole(660, 'support');
        $supportId = $this->memberByTg(660)->id;

        [$pData] = $this->registerTg(661, name: 'Partner');
        $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'Нужна помощь', 'body' => 'детали'], $this->tgHeaders($pData))->assertStatus(201);

        $this->assertSame(1, NotificationOutbox::where('member_id', $supportId)
            ->where('kind', 'ticket.member')->count());
    }

    public function testPartnerMessageEnqueuesNotificationToOperators(): void
    {
        $this->registerTg(670, name: 'Owner');
        $this->grantRole(670, 'owner');
        $ownerId = $this->memberByTg(670)->id;

        [$pData] = $this->registerTg(671, name: 'Partner');
        $id = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'тема', 'body' => 'первое'], $this->tgHeaders($pData))->json('data.id');
        // Чистим, чтобы посчитать только вклад нового сообщения.
        NotificationOutbox::where('member_id', $ownerId)->delete();

        $this->postJson("/api/v1/cabinet/tickets/{$id}/messages",
            ['body' => 'второе'], $this->tgHeaders($pData))->assertStatus(201);

        $this->assertSame(1, NotificationOutbox::where('member_id', $ownerId)
            ->where('kind', 'ticket.member')->count());
    }

    public function testOperatorReplyEnqueuesNotificationToMember(): void
    {
        [$opData] = $this->registerTg(680, name: 'Op');
        $this->grantRole(680, 'support');

        [$pData] = $this->registerTg(681, name: 'Partner');
        $partnerId = $this->memberByTg(681)->id;
        $id = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'тема', 'body' => 'первое'], $this->tgHeaders($pData))->json('data.id');

        $this->postJson("/api/v1/admin/tickets/{$id}/reply",
            ['body' => 'Ответ оператора'], $this->adminHeaders($opData))->assertStatus(201);

        $this->assertSame(1, NotificationOutbox::where('member_id', $partnerId)
            ->where('kind', 'ticket.reply')->count());
    }

    public function testOperatorReplyDedupKeyIsIdempotentPerMessage(): void
    {
        [$opData] = $this->registerTg(685, name: 'Op');
        $this->grantRole(685, 'support');
        [$pData] = $this->registerTg(686, name: 'Partner');
        $partnerId = $this->memberByTg(686)->id;
        $id = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'тема', 'body' => 'первое'], $this->tgHeaders($pData))->json('data.id');

        $msgId = $this->postJson("/api/v1/admin/tickets/{$id}/reply",
            ['body' => 'Ответ'], $this->adminHeaders($opData))->json('data.id');

        // dedup_key = ticket.reply:msg:<id> — уникален по сообщению.
        $this->assertSame(1, NotificationOutbox::where('dedup_key', "ticket.reply:msg:{$msgId}")->count());
    }

    // --- Admin: RBAC ---

    public function testOperatorCanListReplyStatusAssign(): void
    {
        [$opData] = $this->registerTg(700, name: 'Op');
        $this->grantRole(700, 'support');
        $opId = $this->memberByTg(700)->id;

        [$pData] = $this->registerTg(701, name: 'Partner');
        $id = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'тема', 'body' => 'первое'], $this->tgHeaders($pData))->json('data.id');

        // index
        $list = $this->getJson('/api/v1/admin/tickets', $this->adminHeaders($opData))->assertOk();
        $this->assertGreaterThanOrEqual(1, count($list->json('data')));

        // reply
        $this->postJson("/api/v1/admin/tickets/{$id}/reply",
            ['body' => 'ответ'], $this->adminHeaders($opData))->assertStatus(201);
        // open → in_progress после первого ответа
        $this->assertSame('in_progress',
            $this->getJson("/api/v1/admin/tickets/{$id}", $this->adminHeaders($opData))->json('data.ticket.status'));

        // status
        $this->postJson("/api/v1/admin/tickets/{$id}/status",
            ['status' => 'resolved'], $this->adminHeaders($opData))->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        // assign (взять на себя)
        $this->postJson("/api/v1/admin/tickets/{$id}/assign",
            [], $this->adminHeaders($opData))->assertOk()
            ->assertJsonPath('data.assigned_to', $opId);

        // unassign (assigned_to=null)
        $this->postJson("/api/v1/admin/tickets/{$id}/assign",
            ['assigned_to' => null], $this->adminHeaders($opData))->assertOk()
            ->assertJsonPath('data.assigned_to', null);
    }

    public function testAssignRejectsNonOperatorTarget(): void
    {
        [$opData] = $this->registerTg(740, name: 'Op');
        $this->grantRole(740, 'support');
        [$pData] = $this->registerTg(741, name: 'Partner');
        $partnerId = $this->memberByTg(741)->id; // обычный партнёр, НЕ оператор
        $id = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'тема', 'body' => 'x'], $this->tgHeaders($pData))->json('data.id');

        // Назначение на не-оператора → 422 (а не молчаливое «висит»/500 FK).
        $this->postJson("/api/v1/admin/tickets/{$id}/assign",
            ['assigned_to' => $partnerId], $this->adminHeaders($opData))->assertStatus(422);
        // Назначение на несуществующий id → 422 (а не FK 500).
        $this->postJson("/api/v1/admin/tickets/{$id}/assign",
            ['assigned_to' => 999999], $this->adminHeaders($opData))->assertStatus(422);

        // Тикет остался без назначения.
        $this->assertNull(
            $this->getJson("/api/v1/admin/tickets/{$id}", $this->adminHeaders($opData))->json('data.ticket.assigned_to'));
    }

    public function testAdminFilterByStatusAndAssigned(): void
    {
        [$opData] = $this->registerTg(710, name: 'Op');
        $this->grantRole(710, 'support');
        [$pData] = $this->registerTg(711, name: 'Partner');
        $id = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'тема', 'body' => 'x'], $this->tgHeaders($pData))->json('data.id');

        // unassigned фильтр видит новый тикет
        $un = $this->getJson('/api/v1/admin/tickets?assigned=unassigned', $this->adminHeaders($opData))->assertOk();
        $this->assertGreaterThanOrEqual(1, count($un->json('data')));

        // status=closed не видит open-тикет
        $closed = $this->getJson('/api/v1/admin/tickets?status=closed', $this->adminHeaders($opData))->assertOk();
        $ids = array_column($closed->json('data'), 'id');
        $this->assertNotContains($id, $ids);
    }

    public function testFinanceAndLeaderCannotOperate(): void
    {
        [$pData] = $this->registerTg(720, name: 'Partner');
        $id = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'тема', 'body' => 'x'], $this->tgHeaders($pData))->json('data.id');

        [$finData] = $this->registerTg(721, name: 'Finance');
        $this->grantRole(721, 'finance');
        $this->getJson('/api/v1/admin/tickets', $this->adminHeaders($finData))->assertStatus(403);
        $this->postJson("/api/v1/admin/tickets/{$id}/reply",
            ['body' => 'x'], $this->adminHeaders($finData))->assertStatus(403);
        $this->postJson("/api/v1/admin/tickets/{$id}/status",
            ['status' => 'closed'], $this->adminHeaders($finData))->assertStatus(403);
        $this->postJson("/api/v1/admin/tickets/{$id}/assign",
            [], $this->adminHeaders($finData))->assertStatus(403);

        [$leadData] = $this->registerTg(722, name: 'Leader');
        $this->grantRole(722, 'leader');
        $this->getJson('/api/v1/admin/tickets', $this->adminHeaders($leadData))->assertStatus(403);
        $this->postJson("/api/v1/admin/tickets/{$id}/reply",
            ['body' => 'x'], $this->adminHeaders($leadData))->assertStatus(403);
    }

    public function testOwnerCanOperate(): void
    {
        [$ownerData] = $this->registerTg(730, name: 'Owner');
        $this->grantRole(730, 'owner');
        [$pData] = $this->registerTg(731, name: 'Partner');
        $id = $this->postJson('/api/v1/cabinet/tickets',
            ['subject' => 'тема', 'body' => 'x'], $this->tgHeaders($pData))->json('data.id');

        $this->getJson('/api/v1/admin/tickets', $this->adminHeaders($ownerData))->assertOk();
        $this->postJson("/api/v1/admin/tickets/{$id}/reply",
            ['body' => 'ответ owner'], $this->adminHeaders($ownerData))->assertStatus(201);
    }

    public function testAdminRequiresAuth(): void
    {
        $this->getJson('/api/v1/admin/tickets', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
        $this->postJson('/api/v1/admin/tickets/1/reply',
            ['body' => 'x'], ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
    }
}
