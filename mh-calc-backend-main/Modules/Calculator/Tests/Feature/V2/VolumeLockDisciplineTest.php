<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Services\Volume\BinaryMatchingService;
use Modules\Calculator\V2\Services\Volume\PvLotIngestService;
use Modules\Calculator\V2\Services\Volume\PvLotVolumeService;
use Tests\TestCase;

/**
 * Ревью W1 MF-7 (amendments #5): ВСЕ мутации PV-лотов вне оплаты — периодный
 * матчинг, ручной админ-матчинг, reversal несматченного — идут под advisory-lock
 * активаций: лок берёт внешний оркестратор, сервисы делают assertLockHeld().
 *
 * ВАЖНО для сценариев тестов: buyAndPay/markPaid берёт advisory-xact-lock, и под
 * RefreshDatabase он живёт до конца теста (общая обёрточная транзакция) — поэтому
 * negative-кейсы «без лока» не делают ни одной оплаты в своей сессии.
 */
class VolumeLockDisciplineTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        $this->enableFeatureFlags('mh_v2_volumes');
    }

    public function testRunMatchingForPeriodRequiresActivationLock(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/advisory-lock/');

        app(PvLotVolumeService::class)->runMatchingForPeriod('2026-07-H1');
    }

    public function testRunMatchingRequiresActivationLock(): void
    {
        $member = app(MemberService::class)->registerTelegram(700100, 'L', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/advisory-lock/');

        app(BinaryMatchingService::class)->runMatching($member->id, now(), '2026-07-H1', 'run-nolock');
    }

    public function testReverseUnmatchedLotsRequiresActivationLock(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/advisory-lock/');

        app(PvLotIngestService::class)->reverseUnmatchedLotsForOrder(424242, 'без лока');
    }

    public function testAdminRunMatchingOrchestratorTakesLockItself(): void
    {
        // Оркестратор (админ-контроллер) берёт лок сам: сессия БЕЗ единой оплаты
        // (лок никем не взят) — эндпоинт обязан отработать, а не падать на guard'е.
        [$rootData] = $this->registerTg(700200, name: 'Owner');
        $this->grantRole(700200, 'owner');
        $rootId = $this->memberByTg(700200)->id;

        $match = $this->postJson('/api/v1/admin/v2/volumes/binary-matches/run',
            ['member_id' => $rootId, 'period_key' => '2026-07-H1'], $this->adminHeaders($rootData))
            ->assertOk()->json('data');

        $this->assertSame(0, bccomp((string) $match['matched_pv'], '0', 6)); // нулевой матч персистится
    }

    public function testCutoffForPeriodRejectsMonth13(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PvLotVolumeService::cutoffForPeriod('2026-13-H1');
    }

    public function testAdminRunMatchingRejectsMonth13(): void
    {
        // Примечание ревью W1 #1: '2026-13' проходил '(\d{2})' и нормализовался
        // Carbon'ом в 2027-01 — период не того окна.
        [$rootData] = $this->registerTg(700300, name: 'Owner13');
        $this->grantRole(700300, 'owner');

        $this->postJson('/api/v1/admin/v2/volumes/binary-matches/run',
            ['member_id' => $this->memberByTg(700300)->id, 'period_key' => '2026-13-H1'],
            $this->adminHeaders($rootData))
            ->assertStatus(422);
    }
}
