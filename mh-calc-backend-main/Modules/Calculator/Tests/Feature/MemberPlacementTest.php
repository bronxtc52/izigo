<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\PlanSetting;
use Modules\Calculator\Services\MemberService;
use Tests\TestCase;

/**
 * Размещение участников в бинар-дереве: корень, авто-спилловер в слабую ногу,
 * ручной выбор слота и его валидация. Идентичность — Telegram (telegram_id).
 */
class MemberPlacementTest extends TestCase
{
    use RefreshDatabase;

    private int $tg = 1000;

    private function members(): MemberService
    {
        return app(MemberService::class);
    }

    /** Создать участника через Telegram-регистрацию (telegram_id выдаётся автоматически). */
    private function reg(
        string $name,
        ?string $sponsorRef = null,
        ?string $parentRef = null,
        ?string $position = null,
    ): Member {
        return $this->members()->registerTelegram(
            $this->tg++,
            $name,
            null,
            $sponsorRef,
            null,
            $parentRef,
            $position,
        );
    }

    public function testFirstMemberBecomesRoot(): void
    {
        $root = $this->reg('Root');

        $this->assertNull($root->parent_id);
        $this->assertNull($root->position);
        $this->assertSame((string) $root->id, $root->path);
    }

    public function testAutoSpilloverFillsSponsorLegsThenWeakLeg(): void
    {
        $root = $this->reg('Root');

        $a = $this->reg('A', $root->ref_code);
        $b = $this->reg('B', $root->ref_code);
        $c = $this->reg('C', $root->ref_code);

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
        $root = $this->reg('Root');

        $a = $this->reg(
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
        $root = $this->reg('Root');

        $this->reg('A', $root->ref_code, $root->ref_code, 'left');

        $this->expectException(InvalidArgumentException::class);
        $this->reg('B', $root->ref_code, $root->ref_code, 'left');
    }

    public function testCannotPlaceTwoMembersInSameSlotUniqueGuard(): void
    {
        // Гарант на уровне БД: уникальный (parent_id, position).
        $root = $this->reg('Root');
        $this->reg('A', $root->ref_code);

        $taken = Member::where('parent_id', $root->id)->where('position', 'left')->count();
        $this->assertSame(1, $taken);
    }
}
