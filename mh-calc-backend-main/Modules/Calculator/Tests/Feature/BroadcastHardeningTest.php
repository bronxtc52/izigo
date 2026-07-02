<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\NotificationBroadcast;
use Modules\Calculator\Models\NotificationInbox;
use Modules\Calculator\Models\NotificationOutbox;
use Modules\Calculator\Services\Notification\BroadcastService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * B6 (P1-hardening): идемпотентность рассылок. Dedup-ключ детерминирован по содержимому
 * (сегмент+текст), а не по id записи — повтор после таймаута/двойного клика не задваивает
 * доставку; допоставка (resume) достраивает только недостающих.
 */
class BroadcastHardeningTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        $this->enableFeatureFlags('c1_notifications');
    }

    /** owner + N партнёров; возвращает [ownerInitData, ownerId]. */
    private function bootNetwork(int $base, int $members = 2): array
    {
        [$ownerData, $ownerRef] = $this->registerTg($base, name: 'Owner');
        $this->grantRole($base, 'owner');
        for ($i = 1; $i <= $members; $i++) {
            $this->registerTg($base + $i, $ownerRef, "M{$i}");
        }

        return [$ownerData, $this->memberByTg($base)->id];
    }

    public function testRepeatDispatchSameContentDoesNotDuplicate(): void
    {
        [, $ownerId] = $this->bootNetwork(5000, 2);
        $svc = app(BroadcastService::class);

        $first = $svc->dispatch($ownerId, 'all', null, 'Всем привет!');
        $outboxAfterFirst = NotificationOutbox::query()->count();
        $inboxAfterFirst = NotificationInbox::query()->count();
        $this->assertSame(3, $first['enqueued']); // owner + 2 партнёра

        // Ретрай после таймаута / двойной клик: новая broadcast-запись, но доставка не двоится.
        $second = $svc->dispatch($ownerId, 'all', null, 'Всем привет!');
        $this->assertSame(0, $second['enqueued']);
        $this->assertSame($outboxAfterFirst, NotificationOutbox::query()->count());
        $this->assertSame($inboxAfterFirst, NotificationInbox::query()->count());
    }

    public function testDifferentContentEnqueuesAgain(): void
    {
        [, $ownerId] = $this->bootNetwork(5010, 1);
        $svc = app(BroadcastService::class);

        $svc->dispatch($ownerId, 'all', null, 'Текст один');
        $count = NotificationOutbox::query()->count();

        $svc->dispatch($ownerId, 'all', null, 'Текст другой');
        $this->assertSame($count * 2, NotificationOutbox::query()->count());
    }

    public function testResumeDeliversOnlyMissing(): void
    {
        [$ownerData, $ownerId] = $this->bootNetwork(5020, 2);
        $svc = app(BroadcastService::class);

        $res = $svc->dispatch($ownerId, 'all', null, 'Важное сообщение');
        $broadcastId = $res['broadcast_id'];

        // Эмулируем «упали посреди постановки»: часть записей исчезла, статус processing.
        $victim = $this->memberByTg(5021)->id;
        NotificationOutbox::query()->where('member_id', $victim)->delete();
        NotificationInbox::query()->where('member_id', $victim)->delete();
        NotificationBroadcast::query()->where('id', $broadcastId)
            ->update(['status' => NotificationBroadcast::STATUS_PROCESSING]);

        $resumed = $this->postJson("/api/v1/admin/broadcasts/{$broadcastId}/resume", [], $this->adminHeaders($ownerData))
            ->assertOk()->json('data');

        // Достроен ровно недостающий; у остальных — по одной записи.
        $this->assertSame(1, $resumed['enqueued']);
        $this->assertSame(NotificationBroadcast::STATUS_DONE, NotificationBroadcast::find($broadcastId)->status);
        $this->assertSame(1, NotificationOutbox::query()->where('member_id', $victim)->count());
        $this->assertSame(3, NotificationOutbox::query()->count());
        $this->assertSame(3, NotificationInbox::query()->count());
    }

    public function testResumeRejectedForDoneBroadcast(): void
    {
        [$ownerData, $ownerId] = $this->bootNetwork(5030, 1);
        $res = app(BroadcastService::class)->dispatch($ownerId, 'all', null, 'Готовая рассылка');

        $this->postJson("/api/v1/admin/broadcasts/{$res['broadcast_id']}/resume", [], $this->adminHeaders($ownerData))
            ->assertStatus(422);
    }

    public function testBulkEnqueueWritesInboxAndOutboxPairs(): void
    {
        // Bulk-путь сохраняет контракт backbone: outbox+inbox парой на каждого получателя.
        [, $ownerId] = $this->bootNetwork(5040, 2);
        app(BroadcastService::class)->dispatch($ownerId, 'all', null, 'Парная запись');

        $this->assertSame(3, NotificationOutbox::query()->count());
        $this->assertSame(3, NotificationInbox::query()->count());
        $outboxMembers = NotificationOutbox::query()->pluck('member_id')->sort()->values()->all();
        $inboxMembers = NotificationInbox::query()->pluck('member_id')->sort()->values()->all();
        $this->assertSame($outboxMembers, $inboxMembers);
    }
}
