<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Calculator\Models\NotificationInbox;
use Modules\Calculator\Models\NotificationOutbox;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\Services\Notification\BroadcastService;
use Modules\Calculator\Services\Notification\NotificationService;
use Modules\Calculator\Services\Notification\OutboxDispatcher;
use Modules\Calculator\Services\Notification\SegmentResolver;
use Tests\TestCase;

/**
 * C1 (Block C) — ядро уведомлений: идемпотентность enqueue, транзакционность inbox+outbox,
 * диспетчер (success/fail/backoff/max), сегменты и рассылки. Доставку в Telegram мокаем
 * (Http::fake) — в сеть не ходим.
 */
class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): NotificationService
    {
        return app(NotificationService::class);
    }

    private function member(int $tgId, string $name): int
    {
        return app(MemberService::class)->registerTelegram($tgId, $name, 'u' . $tgId)->id;
    }

    public function testEnqueueWritesInboxAndOutboxTogether(): void
    {
        $m = $this->member(9001, 'A');
        $this->svc()->enqueueToMember($m, 'test.kind', '<b>hi</b>', 'Заголовок', null, ['x' => 1]);

        $this->assertDatabaseHas('notification_inbox', ['member_id' => $m, 'kind' => 'test.kind', 'title' => 'Заголовок']);
        $this->assertDatabaseHas('notification_outbox', [
            'member_id' => $m,
            'kind' => 'test.kind',
            'status' => NotificationOutbox::STATUS_PENDING,
            'chat_id' => 9001,
        ]);
    }

    public function testEnqueueRespectsInboxFalse(): void
    {
        $m = $this->member(9002, 'B');
        $this->svc()->enqueueToMember($m, 'k', 'body', null, null, null, false);

        $this->assertSame(0, NotificationInbox::where('member_id', $m)->count());
        $this->assertSame(1, NotificationOutbox::where('member_id', $m)->count());
    }

    public function testEnqueueIsIdempotentByDedupKey(): void
    {
        $m = $this->member(9003, 'C');
        $key = 'payout.status:wd:5:paid';

        $this->svc()->enqueueToMember($m, 'payout.status', 'body', null, $key);
        $this->svc()->enqueueToMember($m, 'payout.status', 'body', null, $key);
        $this->svc()->enqueueToMember($m, 'payout.status', 'body', null, $key);

        $this->assertSame(1, NotificationOutbox::where('dedup_key', $key)->count());
        // inbox тоже не дублируется (повтор пропускается целиком).
        $this->assertSame(1, NotificationInbox::where('member_id', $m)->count());
    }

    public function testEnqueueForMembersScopesDedupKeyPerMember(): void
    {
        $a = $this->member(9004, 'D');
        $b = $this->member(9005, 'E');

        // Один и тот же базовый ключ для пачки — должен стать уникальным на участника.
        $this->svc()->enqueueForMembers([$a, $b], 'broadcast', 'body', null, 'broadcast:7');
        $this->svc()->enqueueForMembers([$a, $b], 'broadcast', 'body', null, 'broadcast:7'); // повтор не дублирует

        $this->assertSame(2, NotificationOutbox::where('kind', 'broadcast')->count());
    }

    public function testDispatcherSendsPendingAndMarksSent(): void
    {
        Http::fake();
        config(['calculator.telegram_notify_enabled' => true, 'calculator.telegram_bot_token' => 'TT']);

        $m = $this->member(9006, 'F');
        $this->svc()->enqueueToMember($m, 'k', '<b>x</b>', null, null, null, false);

        $stats = app(OutboxDispatcher::class)->dispatch();

        $this->assertSame(1, $stats['sent']);
        $this->assertDatabaseHas('notification_outbox', ['member_id' => $m, 'status' => NotificationOutbox::STATUS_SENT]);
        Http::assertSent(fn ($req) => $req['chat_id'] == 9006);
    }

    public function testDispatcherSkipsWhenNoChatId(): void
    {
        Http::fake();
        config(['calculator.telegram_notify_enabled' => true, 'calculator.telegram_bot_token' => 'TT']);

        // outbox с member, но chat_id вручную обнулим (участник без telegram_id — крайний случай).
        $m = $this->member(9007, 'G');
        $this->svc()->enqueueToMember($m, 'k', 'x', null, null, null, false);
        NotificationOutbox::where('member_id', $m)->update(['chat_id' => null]);

        $stats = app(OutboxDispatcher::class)->dispatch();

        $this->assertSame(1, $stats['skipped']);
        $this->assertDatabaseHas('notification_outbox', ['member_id' => $m, 'status' => NotificationOutbox::STATUS_SKIPPED]);
        Http::assertNothingSent();
    }

    public function testDispatcherRetriesOnTransientErrorThenFails(): void
    {
        // deliver()='retry' (сеть/429/5xx) → attempts++ и backoff; на max_attempts → failed.
        $retrying = new class extends \Modules\Calculator\Services\Telegram\TelegramNotifier {
            public function deliver(?int $chatId, string $html): string
            {
                return 'retry';
            }
        };
        $dispatcher = new OutboxDispatcher($retrying);

        $m = $this->member(9008, 'H');
        $this->svc()->enqueueToMember($m, 'k', 'x', null, null, null, false);
        NotificationOutbox::where('member_id', $m)->update(['max_attempts' => 2]);

        // Первый прогон: attempts=1, обратно в pending с будущим available_at.
        $dispatcher->dispatch();
        $row = NotificationOutbox::where('member_id', $m)->first();
        $this->assertSame(1, $row->attempts);
        $this->assertSame(NotificationOutbox::STATUS_PENDING, $row->status);
        $this->assertTrue($row->available_at->isFuture());
        // last_error не содержит токена/URL.
        $this->assertStringNotContainsString('api.telegram.org', (string) $row->last_error);

        // Делаем доступной снова и второй прогон → attempts=2 == max → failed.
        NotificationOutbox::where('member_id', $m)->update(['available_at' => now()->subMinute()]);
        $dispatcher->dispatch();
        $row->refresh();
        $this->assertSame(2, $row->attempts);
        $this->assertSame(NotificationOutbox::STATUS_FAILED, $row->status);
    }

    public function testDispatcherFailsImmediatelyOnTerminalError(): void
    {
        // deliver()='failed' (4xx: chat not found/bot blocked) → сразу failed, без ретраев.
        $failing = new class extends \Modules\Calculator\Services\Telegram\TelegramNotifier {
            public function deliver(?int $chatId, string $html): string
            {
                return 'failed';
            }
        };
        $dispatcher = new OutboxDispatcher($failing);

        $m = $this->member(9009, 'I');
        $this->svc()->enqueueToMember($m, 'k', 'x', null, null, null, false);

        $stats = $dispatcher->dispatch();
        $this->assertSame(1, $stats['failed']);
        $row = NotificationOutbox::where('member_id', $m)->first();
        $this->assertSame(NotificationOutbox::STATUS_FAILED, $row->status);
        $this->assertSame(1, $row->attempts);
    }

    public function testDispatcherReapsStuckSending(): void
    {
        Http::fake();
        config(['calculator.telegram_notify_enabled' => true, 'calculator.telegram_bot_token' => 'TT']);

        $m = $this->member(9010, 'J');
        $this->svc()->enqueueToMember($m, 'k', 'x', null, null, null, false);
        // Симулируем упавший процесс: запись зависла в sending давно.
        NotificationOutbox::where('member_id', $m)->update([
            'status' => NotificationOutbox::STATUS_SENDING,
            'updated_at' => now()->subMinutes(30),
        ]);

        $stats = app(OutboxDispatcher::class)->dispatch();
        $this->assertSame(1, $stats['reaped']);
        // Реанимирована и тут же доставлена в этом же прогоне.
        $this->assertDatabaseHas('notification_outbox', ['member_id' => $m, 'status' => NotificationOutbox::STATUS_SENT]);
    }

    public function testSegmentResolver(): void
    {
        $a = $this->member(9101, 'A'); // registered по умолчанию
        $b = $this->member(9102, 'B');
        \Modules\Calculator\Models\Member::whereKey($b)->update(['status' => 'active', 'rank_id' => 3]);

        $resolver = app(SegmentResolver::class);
        $this->assertSame(2, $resolver->count('all'));
        $this->assertSame(1, $resolver->count('by_status', 'active'));
        $this->assertSame(1, $resolver->count('by_status', 'registered'));
        $this->assertSame(1, $resolver->count('by_rank', '3'));

        $ids = $resolver->resolve('by_status', 'active');
        $this->assertSame([$b], $ids);
    }

    public function testSegmentResolverRejectsBadSegment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(SegmentResolver::class)->count('by_status', 'nope');
    }

    public function testBroadcastPreviewDoesNotWrite(): void
    {
        $this->member(9201, 'A');
        $this->member(9202, 'B');

        $res = app(BroadcastService::class)->preview('all');

        $this->assertSame(2, $res['recipients_count']);
        $this->assertSame(0, NotificationOutbox::count());
        $this->assertDatabaseCount('notification_broadcasts', 0);
    }

    public function testBroadcastDispatchEnqueuesAll(): void
    {
        $a = $this->member(9301, 'A');
        $b = $this->member(9302, 'B');

        $res = app(BroadcastService::class)->dispatch($a, 'all', null, "**Привет** всем\n- пункт");

        $this->assertSame(2, $res['recipients_count']);
        $this->assertSame(2, NotificationOutbox::where('kind', 'broadcast')->count());
        $this->assertDatabaseHas('notification_broadcasts', ['recipients_count' => 2, 'status' => 'done']);

        // Текст нормализован в Telegram-HTML на выходе; сырьё хранится в body_raw.
        $out = NotificationOutbox::where('member_id', $a)->where('kind', 'broadcast')->first();
        $this->assertStringContainsString('<b>Привет</b>', $out->body);
        $this->assertStringContainsString('• пункт', $out->body);
        $this->assertDatabaseHas('notification_broadcasts', ['body_raw' => "**Привет** всем\n- пункт"]);
    }
}
