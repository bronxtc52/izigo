<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\NotificationOutbox;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * C7 (Block C) — READ-ONLY мониторинг outbox/планировщика в веб-админке.
 *
 * Фон проекта = планировщик (НЕ async-очередь): мониторим notification_outbox (C1)
 * + здоровье диспетчера; failed_jobs справочно. Только owner, строго read-only
 * (write-роутов нет). Проверяем: корректность counts, порог застрявших, RBAC
 * (owner ok; finance/support/leader → 403; без auth → 401), отсутствие write-путей.
 */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** Создать outbox-запись напрямую, с контролем времён (для тестов застрявших). */
    private function makeOutbox(int $memberId, array $attrs = []): int
    {
        $now = Carbon::now();
        $id = DB::table('notification_outbox')->insertGetId(array_merge([
            'member_id' => $memberId,
            'channel' => 'telegram',
            'chat_id' => 555,
            'kind' => 'test',
            'body' => '<b>x</b>',
            'status' => NotificationOutbox::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 5,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attrs));

        return (int) $id;
    }

    private function owner(int $tg): string
    {
        [$data] = $this->registerTg($tg, name: 'Owner');
        $this->grantRole($tg, 'owner');

        return $data;
    }

    // --- Корректность подсчётов ---

    public function testOutboxSummaryCountsByStatus(): void
    {
        $ownerData = $this->owner(700);
        $memberId = $this->memberByTg(700)->id;

        $this->makeOutbox($memberId, ['status' => NotificationOutbox::STATUS_PENDING]);
        $this->makeOutbox($memberId, ['status' => NotificationOutbox::STATUS_PENDING]);
        $this->makeOutbox($memberId, ['status' => NotificationOutbox::STATUS_SENT, 'sent_at' => Carbon::now()]);
        $this->makeOutbox($memberId, ['status' => NotificationOutbox::STATUS_FAILED, 'last_error' => 'telegram rejected (4xx)']);
        $this->makeOutbox($memberId, ['status' => NotificationOutbox::STATUS_SKIPPED, 'last_error' => 'no chat_id']);

        $res = $this->getJson('/api/v1/admin/monitoring/outbox', $this->adminHeaders($ownerData))->assertOk();

        $this->assertSame(2, $res->json('data.counts.pending'));
        $this->assertSame(1, $res->json('data.counts.sent'));
        $this->assertSame(1, $res->json('data.counts.failed'));
        $this->assertSame(1, $res->json('data.counts.skipped'));
        $this->assertSame(0, $res->json('data.counts.sending'));
        $this->assertSame(5, $res->json('data.counts.total'));
    }

    public function testStuckCountRespectsThreshold(): void
    {
        $ownerData = $this->owner(710);
        $memberId = $this->memberByTg(710)->id;

        // Свежая pending (готова, но только что создана) — НЕ застрявшая.
        $this->makeOutbox($memberId, ['status' => NotificationOutbox::STATUS_PENDING]);

        // Застрявшая: pending, available_at в прошлом, updated_at старше порога.
        $old = Carbon::now()->subMinutes(30);
        $this->makeOutbox($memberId, [
            'status' => NotificationOutbox::STATUS_PENDING,
            'available_at' => $old,
            'updated_at' => $old,
            'created_at' => $old,
        ]);

        // Застрявшая в sending (упавший процесс).
        $this->makeOutbox($memberId, [
            'status' => NotificationOutbox::STATUS_SENDING,
            'available_at' => $old,
            'updated_at' => $old,
            'created_at' => $old,
        ]);

        // Будущая available_at, старый updated_at — ещё НЕ время отправки → не застрявшая.
        $this->makeOutbox($memberId, [
            'status' => NotificationOutbox::STATUS_PENDING,
            'available_at' => Carbon::now()->addHour(),
            'updated_at' => $old,
            'created_at' => $old,
        ]);

        $res = $this->getJson('/api/v1/admin/monitoring/outbox', $this->adminHeaders($ownerData))->assertOk();

        $this->assertSame(2, $res->json('data.stuck.count'));
        $this->assertSame(10, $res->json('data.stuck.threshold_minutes'));
    }

    public function testProblemRecordsReturnFailedAndStuckWithLastError(): void
    {
        $ownerData = $this->owner(720);
        $memberId = $this->memberByTg(720)->id;

        $this->makeOutbox($memberId, [
            'status' => NotificationOutbox::STATUS_FAILED,
            'attempts' => 5,
            'last_error' => 'telegram rejected (4xx)',
        ]);
        // sent — не проблемная, не должна попасть.
        $this->makeOutbox($memberId, ['status' => NotificationOutbox::STATUS_SENT, 'sent_at' => Carbon::now()]);

        $res = $this->getJson('/api/v1/admin/monitoring/outbox/problems', $this->adminHeaders($ownerData))->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('failed', $res->json('data.0.status'));
        $this->assertSame('telegram rejected (4xx)', $res->json('data.0.last_error'));
        $this->assertSame(5, $res->json('data.0.attempts'));
    }

    public function testFailedJobsReportedAsReference(): void
    {
        $ownerData = $this->owner(730);

        $res = $this->getJson('/api/v1/admin/monitoring/outbox', $this->adminHeaders($ownerData))->assertOk();

        // failed_jobs присутствует справочно с пометкой (очередь sync — обычно пусто).
        $this->assertArrayHasKey('failed_jobs', $res->json('data'));
        $this->assertSame('queue sync — usually empty', $res->json('data.failed_jobs.note'));
    }

    // --- RBAC: owner-only (бэкенд, не только UI) ---

    public function testOwnerCanView(): void
    {
        $ownerData = $this->owner(740);
        $this->getJson('/api/v1/admin/monitoring/outbox', $this->adminHeaders($ownerData))->assertOk();
        $this->getJson('/api/v1/admin/monitoring/outbox/problems', $this->adminHeaders($ownerData))->assertOk();
    }

    public function testNonOwnerRolesForbidden(): void
    {
        foreach (['finance', 'support', 'leader'] as $i => $role) {
            $tg = 750 + $i;
            [$data] = $this->registerTg($tg, name: ucfirst($role));
            $this->grantRole($tg, $role);

            $this->getJson('/api/v1/admin/monitoring/outbox', $this->adminHeaders($data))
                ->assertStatus(403);
            $this->getJson('/api/v1/admin/monitoring/outbox/problems', $this->adminHeaders($data))
                ->assertStatus(403);
        }
    }

    public function testRequiresAuth(): void
    {
        $this->getJson('/api/v1/admin/monitoring/outbox', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
        $this->getJson('/api/v1/admin/monitoring/outbox/problems', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    // --- Read-only: write-роутов мониторинга нет ---

    public function testNoWriteRoutesExist(): void
    {
        $ownerData = $this->owner(760);

        foreach (['monitoring/outbox', 'monitoring/outbox/problems'] as $path) {
            $post = $this->postJson("/api/v1/admin/{$path}", [], $this->adminHeaders($ownerData));
            $this->assertContains($post->getStatusCode(), [404, 405], "POST {$path} must not exist");

            $put = $this->putJson("/api/v1/admin/{$path}", [], $this->adminHeaders($ownerData));
            $this->assertContains($put->getStatusCode(), [404, 405], "PUT {$path} must not exist");

            $delete = $this->deleteJson("/api/v1/admin/{$path}", [], $this->adminHeaders($ownerData));
            $this->assertContains($delete->getStatusCode(), [404, 405], "DELETE {$path} must not exist");
        }
    }
}
