<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Modules\Calculator\Models\CalculatorUser;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\PlanSetting;
use Modules\Calculator\Services\MemberService;
use Tests\TestCase;

/**
 * Размещение участников в бинар-дереве: корень, авто-спилловер в слабую ногу,
 * ручной выбор слота и его валидация.
 */
class MemberPlacementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): int
    {
        return CalculatorUser::create(['email' => $email, 'password' => Hash::make('secret123')])->id;
    }

    private function members(): MemberService
    {
        return app(MemberService::class);
    }

    public function testFirstMemberBecomesRoot(): void
    {
        $root = $this->members()->register($this->user('root@t.dev'), 'Root', null);

        $this->assertNull($root->parent_id);
        $this->assertNull($root->position);
        $this->assertSame((string) $root->id, $root->path);
    }

    public function testAutoSpilloverFillsSponsorLegsThenWeakLeg(): void
    {
        $svc = $this->members();
        $root = $svc->register($this->user('r@t.dev'), 'Root', null);

        $a = $svc->register($this->user('a@t.dev'), 'A', $root->ref_code);
        $b = $svc->register($this->user('b@t.dev'), 'B', $root->ref_code);
        $c = $svc->register($this->user('c@t.dev'), 'C', $root->ref_code);

        // Первые двое заполняют ноги корня.
        $this->assertSame($root->id, $a->parent_id);
        $this->assertSame('left', $a->position);
        $this->assertSame($root->id, $b->parent_id);
        $this->assertSame('right', $b->position);

        // Третий — спилловер в слабую (левую при равенстве) ногу, под A слева.
        $this->assertSame($a->id, $c->parent_id);
        $this->assertSame('left', $c->position);
        $this->assertSame("{$root->id}.{$a->id}.{$c->id}", $c->path);
    }

    public function testManualPlacementUsesChosenSlot(): void
    {
        PlanSetting::put('placement_mode', 'manual');
        $svc = $this->members();
        $root = $svc->register($this->user('mr@t.dev'), 'Root', null);

        $a = $svc->register(
            $this->user('ma@t.dev'),
            'A',
            sponsorRef: $root->ref_code,
            parentRef: $root->ref_code,
            position: 'right',
        );

        $this->assertSame($root->id, $a->parent_id);
        $this->assertSame('right', $a->position);
    }

    public function testManualPlacementRejectsTakenSlot(): void
    {
        PlanSetting::put('placement_mode', 'manual');
        $svc = $this->members();
        $root = $svc->register($this->user('tr@t.dev'), 'Root', null);

        $svc->register($this->user('t1@t.dev'), 'A', $root->ref_code, $root->ref_code, 'left');

        $this->expectException(InvalidArgumentException::class);
        $svc->register($this->user('t2@t.dev'), 'B', $root->ref_code, $root->ref_code, 'left');
    }

    public function testCannotPlaceTwoMembersInSameSlotUniqueGuard(): void
    {
        // Гарант на уровне БД: уникальный (parent_id, position).
        $svc = $this->members();
        $root = $svc->register($this->user('ur@t.dev'), 'Root', null);
        $svc->register($this->user('u1@t.dev'), 'A', $root->ref_code);

        $taken = Member::where('parent_id', $root->id)->where('position', 'left')->count();
        $this->assertSame(1, $taken);
    }
}
