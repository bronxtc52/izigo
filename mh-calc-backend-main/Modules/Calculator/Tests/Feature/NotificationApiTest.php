<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Calculator\Models\NotificationOutbox;
use Modules\Calculator\Services\Notification\NotificationService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * C1 (Block C) — API уведомлений: cabinet inbox (видит только свои, markRead только своё)
 * + admin broadcasts RBAC (owner/support — да; finance/leader/без auth — нет) + хук статуса
 * выплаты (best-effort, после commit, не ломает выплату).
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private const BRONZE = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    private function enqueue(int $tgId, string $body = 'привет'): void
    {
        $memberId = $this->memberByTg($tgId)->id;
        app(NotificationService::class)->enqueueToMember($memberId, 'test', $body, 'T');
    }

    // --- Cabinet inbox ---

    public function testCabinetSeesOnlyOwnNotifications(): void
    {
        [$aData] = $this->registerTg(300, name: 'A');
        [$bData] = $this->registerTg(301, name: 'B');
        $this->enqueue(300, 'для A');
        $this->enqueue(301, 'для B');

        $res = $this->getJson('/api/v1/cabinet/notifications', $this->tgHeaders($aData))->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertStringContainsString('для A', $res->json('data.0.body'));
    }

    public function testUnreadCountAndMarkRead(): void
    {
        [$aData] = $this->registerTg(310, name: 'A');
        $this->enqueue(310);
        $this->enqueue(310);

        $this->getJson('/api/v1/cabinet/notifications/unread-count', $this->tgHeaders($aData))
            ->assertOk()->assertJsonPath('data.unread', 2);

        $id = $this->getJson('/api/v1/cabinet/notifications', $this->tgHeaders($aData))->json('data.0.id');
        $this->postJson("/api/v1/cabinet/notifications/{$id}/read", [], $this->tgHeaders($aData))->assertOk();

        $this->getJson('/api/v1/cabinet/notifications/unread-count', $this->tgHeaders($aData))
            ->assertOk()->assertJsonPath('data.unread', 1);

        $this->postJson('/api/v1/cabinet/notifications/read-all', [], $this->tgHeaders($aData))->assertOk();
        $this->getJson('/api/v1/cabinet/notifications/unread-count', $this->tgHeaders($aData))
            ->assertOk()->assertJsonPath('data.unread', 0);
    }

    public function testCannotMarkReadOthersNotification(): void
    {
        [$aData] = $this->registerTg(320, name: 'A');
        [$bData] = $this->registerTg(321, name: 'B');
        $this->enqueue(320);
        $aId = $this->getJson('/api/v1/cabinet/notifications', $this->tgHeaders($aData))->json('data.0.id');

        // B пытается отметить уведомление A — 404 (не раскрываем существование).
        $this->postJson("/api/v1/cabinet/notifications/{$aId}/read", [], $this->tgHeaders($bData))
            ->assertStatus(404);
    }

    public function testInboxRequiresAuth(): void
    {
        $this->getJson('/api/v1/cabinet/notifications', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    // --- Admin broadcasts RBAC ---

    public function testOwnerCanPreviewAndSendBroadcast(): void
    {
        [$ownerData] = $this->registerTg(330, name: 'Owner');
        $this->grantRole(330, 'owner');
        $this->registerTg(331, name: 'Plain');

        $this->postJson('/api/v1/admin/broadcasts/preview', ['segment_type' => 'all'], $this->adminHeaders($ownerData))
            ->assertOk()->assertJsonPath('data.recipients_count', 2);

        $this->postJson('/api/v1/admin/broadcasts',
            ['segment_type' => 'all', 'body' => '**Объявление**'], $this->adminHeaders($ownerData))
            ->assertOk()->assertJsonPath('data.recipients_count', 2);

        $this->assertSame(2, NotificationOutbox::where('kind', 'broadcast')->count());
    }

    public function testSupportCanSendBroadcast(): void
    {
        [$supportData] = $this->registerTg(340, name: 'Support');
        $this->grantRole(340, 'support');

        $this->postJson('/api/v1/admin/broadcasts/preview', ['segment_type' => 'all'], $this->adminHeaders($supportData))
            ->assertOk();
    }

    public function testFinanceCannotSendBroadcast(): void
    {
        [$finData] = $this->registerTg(350, name: 'Finance');
        $this->grantRole(350, 'finance');

        $this->postJson('/api/v1/admin/broadcasts/preview', ['segment_type' => 'all'], $this->adminHeaders($finData))
            ->assertStatus(403);
        $this->postJson('/api/v1/admin/broadcasts',
            ['segment_type' => 'all', 'body' => 'x'], $this->adminHeaders($finData))
            ->assertStatus(403);
    }

    public function testLeaderCannotSendBroadcast(): void
    {
        [$leaderData] = $this->registerTg(360, name: 'Leader');
        $this->grantRole(360, 'leader');

        $this->postJson('/api/v1/admin/broadcasts/preview', ['segment_type' => 'all'], $this->adminHeaders($leaderData))
            ->assertStatus(403);
    }

    public function testBroadcastRequiresAuth(): void
    {
        $this->postJson('/api/v1/admin/broadcasts/preview', ['segment_type' => 'all'],
            ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
    }

    // --- Payout status hook (best-effort, после commit) ---

    public function testPayoutApproveEnqueuesNotification(): void
    {
        Http::fake();
        [$rootData, $rootRef] = $this->registerTg(400, name: 'Root');
        [$aData] = $this->registerTg(401, $rootRef, 'A');
        [$financeData] = $this->registerTg(402, $rootRef, 'Finance');
        $this->grantRole(402, 'finance');

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData));

        $wd = $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '5.00', 'payout_details' => 'IBAN'], $this->tgHeaders($rootData))->json('data');
        $id = (int) $wd['id'];

        $this->postJson("/api/v1/admin/withdrawals/{$id}/approve", [], $this->adminHeaders($financeData))
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $rootId = $this->memberByTg(400)->id;
        $this->assertDatabaseHas('notification_outbox', [
            'member_id' => $rootId,
            'kind' => 'payout.status',
            'dedup_key' => "payout.status:wd:{$id}:approved",
        ]);
    }

    public function testPayoutHookIsBestEffortAndDoesNotBreakPayout(): void
    {
        // Подменяем NotificationService на падающий — выплата всё равно должна пройти.
        $this->app->bind(NotificationService::class, function () {
            return new class extends NotificationService {
                public function enqueueToMember(int $memberId, string $kind, string $html, ?string $title = null, ?string $dedupKey = null, ?array $data = null, bool $inbox = true): void
                {
                    throw new \RuntimeException('notification subsystem down');
                }
            };
        });

        [$rootData, $rootRef] = $this->registerTg(410, name: 'Root');
        [$aData] = $this->registerTg(411, $rootRef, 'A');
        [$financeData] = $this->registerTg(412, $rootRef, 'Finance');
        $this->grantRole(412, 'finance');

        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($rootData));
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => self::BRONZE], $this->tgHeaders($aData));

        $wd = $this->postJson('/api/v1/cabinet/withdrawals',
            ['amount' => '5.00', 'payout_details' => 'IBAN'], $this->tgHeaders($rootData))->json('data');
        $id = (int) $wd['id'];

        // Несмотря на падающую подсистему уведомлений — выплата проходит (best-effort hook).
        $this->postJson("/api/v1/admin/withdrawals/{$id}/approve", [], $this->adminHeaders($financeData))
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('withdrawal_requests', ['id' => $id, 'status' => 'approved']);
    }
}
