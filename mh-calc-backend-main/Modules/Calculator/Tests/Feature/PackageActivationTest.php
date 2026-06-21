<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Calculator\Models\ActivationEvent;
use Modules\Calculator\Models\CalculatorUser;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberBonusLine;
use Modules\Calculator\Models\MemberEarning;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\MemberService;
use Tests\TestCase;

/**
 * Активация пакета как идемпотентное событие и пересчёт бонусов доменным ядром
 * на ЖИВЫХ данных (через маппер БД → Network). Пакет Bronze = id 1 = 90 PV.
 */
class PackageActivationTest extends TestCase
{
    use RefreshDatabase;

    private const BRONZE = 1;

    private function user(string $email): int
    {
        return CalculatorUser::create(['email' => $email, 'password' => Hash::make('secret123')])->id;
    }

    /** Дерево: Root R с двумя личниками A (left) и B (right). */
    private function buildTriangle(): array
    {
        $svc = app(MemberService::class);
        $root = $svc->register($this->user('r@t.dev'), 'R', null);
        $a = $svc->register($this->user('a@t.dev'), 'A', $root->ref_code);
        $b = $svc->register($this->user('b@t.dev'), 'B', $root->ref_code);

        return [$root, $a, $b];
    }

    public function testActivationIsIdempotent(): void
    {
        [, $a] = $this->buildTriangle();
        $svc = app(ActivationService::class);

        $svc->activate($a->id, self::BRONZE, 'evt-A');
        $svc->activate($a->id, self::BRONZE, 'evt-A'); // повтор того же ключа

        $this->assertSame(1, ActivationEvent::where('idempotency_key', 'evt-A')->count());
        $this->assertSame('active', Member::find($a->id)->status);
        $this->assertSame(self::BRONZE, Member::find($a->id)->package_id);
    }

    public function testPreexistingEventMakesActivationNoop(): void
    {
        // Эмуляция «победителя гонки»: событие с ключом уже есть → повтор не
        // активирует пакет и не пересчитывает (ветка insertOrIgnore === 0).
        [, $a] = $this->buildTriangle();
        ActivationEvent::create([
            'member_id' => $a->id,
            'package_id' => self::BRONZE,
            'idempotency_key' => 'race-key',
            'status' => 'applied',
        ]);

        app(ActivationService::class)->activate($a->id, self::BRONZE, 'race-key');

        $this->assertSame('registered', Member::find($a->id)->status);
        $this->assertSame(0, MemberBonusLine::count());
        $this->assertSame(1, ActivationEvent::where('idempotency_key', 'race-key')->count());
    }

    public function testReferralBonusCreditedToSponsorOnLiveData(): void
    {
        [$root, $a] = $this->buildTriangle();
        $svc = app(ActivationService::class);
        // Спонсор зарабатывает реферал только будучи активным сам.
        $svc->activate($root->id, self::BRONZE, 'evt-R');
        $svc->activate($a->id, self::BRONZE, 'evt-A');

        // Реферальный: 10% от 90 PV пакета Bronze = $9 спонсору (Root).
        $earn = MemberEarning::where('member_id', $root->id)->first();
        $this->assertNotNull($earn);
        $this->assertEqualsWithDelta(9.0, (float) $earn->total, 0.001);
        $this->assertEqualsWithDelta(9.0, (float) ($earn->by_type['referral'] ?? 0), 0.001);

        $line = MemberBonusLine::where('recipient_member_id', $root->id)
            ->where('type', 'referral')->first();
        $this->assertNotNull($line);
    }

    public function testBinaryBonusPairsAfterBothLegsActive(): void
    {
        [$root, $a, $b] = $this->buildTriangle();
        $svc = app(ActivationService::class);
        $svc->activate($root->id, self::BRONZE, 'evt-R');
        $svc->activate($a->id, self::BRONZE, 'evt-A');
        $svc->activate($b->id, self::BRONZE, 'evt-B');

        $earn = MemberEarning::where('member_id', $root->id)->first();
        // Реферальный 2×$9 = $18 + бинар 5% от min(90,90)=90 → $4.5. Итого $22.5.
        $this->assertEqualsWithDelta(18.0, (float) ($earn->by_type['referral'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(4.5, (float) ($earn->by_type['binary'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(22.5, (float) $earn->total, 0.001);
    }

    public function testSnapshotIsReplacedNotAccumulated(): void
    {
        [$root, $a] = $this->buildTriangle();
        $svc = app(ActivationService::class);

        $svc->activate($root->id, self::BRONZE, 'evt-R');
        $svc->activate($a->id, self::BRONZE, 'evt-A');
        $firstCount = MemberBonusLine::count();

        // Повторный тот же ключ — идемпотентно, без пересчёта и дублей снимка.
        $svc->activate($a->id, self::BRONZE, 'evt-A');
        $this->assertSame($firstCount, MemberBonusLine::count());

        // Реферал Root присутствует ровно один раз.
        $this->assertSame(
            1,
            MemberBonusLine::where('recipient_member_id', $root->id)->where('type', 'referral')->count(),
        );
    }
}
