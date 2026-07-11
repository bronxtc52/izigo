<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T04: deny-by-default — при выключенном mh_plan_v2_periods все 5 команд =
 * немедленный no-op (ни периодов, ни runs, ни job-окон), V1-поведение не тронуто.
 */
class PeriodFlagGateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function testAllCommandsAreNoOpWhenFlagOff(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 01:00:00', 'UTC'));

        foreach ([
            'calc-v2:periods-ensure',
            'calc-v2:half-month-close',
            'calc-v2:ns-os-transfer',
            'calc-v2:month-close',
            'calc-v2:quarter-payout',
        ] as $command) {
            $this->artisan($command)->assertExitCode(0);
        }

        $this->assertDatabaseCount('v2_calc_periods', 0);
        $this->assertDatabaseCount('v2_calc_runs', 0);
        $this->assertDatabaseCount('v2_calc_snapshots', 0);
        $this->assertDatabaseCount('v2_calc_job_executions', 0);
    }

    public function testFlagSeededOffByMigration(): void
    {
        $this->assertDatabaseHas('feature_flags', ['key' => 'mh_plan_v2_periods', 'enabled' => false]);
    }
}
