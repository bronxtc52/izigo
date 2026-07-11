<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\FakePolicy;
use Modules\Calculator\Tests\Feature\V2\Support\FakePolicyResolver;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Domain\CalcSnapshot;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Modules\Calculator\V2\Services\Periods\PeriodService;
use Tests\TestCase;

/**
 * T04: снапшоты входов — создаются до шагов, детерминированы (одинаковые входы →
 * одинаковый payload_hash/result_hash, ARCH-NFR-01) и неизменяемы (ДЕНЬГИ:
 * снапшот — доказательство входов закрытия).
 */
class SnapshotTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function testCloseRunFreezesSnapshotWithBaseSections(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20 00:10:00', 'UTC'));
        $this->app->instance(PolicyVersionResolver::class, new FakePolicyResolver(new FakePolicy(versionId: 42, configHash: 'cafebabe')));

        $this->registerTg(9001, name: 'Snap');
        $member = $this->memberByTg(9001);

        // Оплата ВНУТРИ окна H1, вторая — вне окна, третья — не paid: в манифест
        // попадает ровно первая.
        $inside = Payment::query()->create([
            'member_id' => $member->id, 'purpose' => Payment::PURPOSE_TOPUP,
            'amount_cents' => 10_000, 'status' => Payment::STATUS_PAID,
            'paid_at' => Carbon::parse('2026-07-10 12:00:00', 'UTC'), 'external_ref' => 'pay:in',
        ]);
        Payment::query()->create([
            'member_id' => $member->id, 'purpose' => Payment::PURPOSE_TOPUP,
            'amount_cents' => 20_000, 'status' => Payment::STATUS_PAID,
            'paid_at' => Carbon::parse('2026-07-17 12:00:00', 'UTC'), 'external_ref' => 'pay:out',
        ]);
        Payment::query()->create([
            'member_id' => $member->id, 'purpose' => Payment::PURPOSE_TOPUP,
            'amount_cents' => 30_000, 'status' => Payment::STATUS_PENDING,
            'paid_at' => null, 'external_ref' => 'pay:pending',
        ]);

        app(PeriodCloseService::class)->closeHalfMonth('2026-07-H1');

        $run = CalcRun::query()->where('idempotency_key', 'close:half_month:2026-07-H1')->sole();
        $snapshot = CalcSnapshot::query()->where('run_id', $run->id)->sole();
        $this->assertSame($snapshot->id, $run->snapshot_id);

        $payload = $snapshot->payload;
        $this->assertSame('2026-07-H1', $payload['period']['code']);
        $this->assertSame('half_month', $payload['period']['type']);
        $this->assertSame(42, $payload['policy']['policy_version_id']);
        $this->assertSame('cafebabe', $payload['policy']['config_hash']);
        $this->assertCount(1, $payload['payments'], 'только paid-платежи окна [start, end)');
        $this->assertSame($inside->id, $payload['payments'][0]['id']);
        $this->assertSame(10_000, $payload['payments'][0]['amount_cents']);
        $this->assertNotEmpty($snapshot->payload_hash);
    }

    /** ARCH-NFR-01: два preview на идентичных входах → одинаковые hash'и. */
    public function testPreviewRunsAreDeterministic(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20 00:10:00', 'UTC'));
        app(PeriodService::class)->ensureByCode('2026-07-H1');

        $closer = app(PeriodCloseService::class);
        $first = $closer->runPreview('2026-07-H1');
        $second = $closer->runPreview('2026-07-H1');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(CalcRun::MODE_PREVIEW, $first->mode);
        $this->assertSame($first->result_hash, $second->result_hash, 'детерминизм результата');
        $this->assertSame(
            $first->snapshot()->sole()->payload_hash,
            $second->snapshot()->sole()->payload_hash,
            'детерминизм снапшота'
        );
    }

    public function testSnapshotIsImmutable(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20 00:10:00', 'UTC'));
        $run = app(PeriodCloseService::class)->runPreview('2026-07-H1');
        $snapshot = $run->snapshot()->sole();
        $originalHash = $snapshot->payload_hash;

        try {
            $snapshot->update(['payload_hash' => str_repeat('0', 64)]);
            $this->fail('update снапшота должен падать');
        } catch (\LogicException) {
        }
        $this->assertSame($originalHash, $snapshot->fresh()->payload_hash, 'БД не изменилась');

        $this->expectException(\LogicException::class);
        $snapshot->delete();
    }
}
