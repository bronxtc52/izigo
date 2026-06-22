<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\AdminAuditLog;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * B2: ручной перенос участника в дереве (owner-only) + обязательный dry-run preview.
 * Структуру меняем, движок бонусов НЕ трогаем. Проверяем: preview без мутаций, ре-path
 * поддерева, анти-цикл/корень/занятый слот, аудит, RBAC.
 */
class PlacementMoveAdminTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /**
     * Дерево auto-spillover: owner{left:A{left:C}, right:B}.
     * @return array{0:string,1:int,2:int,3:int,4:int} [ownerData, ownerId, aId, bId, cId]
     */
    private function buildTree(): array
    {
        [$ownerData, $ownerRef] = $this->registerTg(300, name: 'Owner');
        $this->grantRole(300, 'owner');
        $this->registerTg(301, $ownerRef, 'A');
        $this->registerTg(302, $ownerRef, 'B');
        $this->registerTg(303, $ownerRef, 'C');

        $owner = $this->memberByTg(300);
        $a = $this->memberByTg(301);
        $b = $this->memberByTg(302);
        $c = $this->memberByTg(303);

        // Санити структуры до переносов.
        $this->assertNull($owner->parent_id);
        $this->assertSame($a->id, $c->parent_id, 'C ожидается под A (spillover)');

        return [$ownerData, $owner->id, $a->id, $b->id, $c->id];
    }

    private function preview(string $admin, int $memberId, int $parentId, string $pos)
    {
        return $this->postJson('/api/v1/admin/genealogy/preview-move',
            ['member_id' => $memberId, 'parent_id' => $parentId, 'position' => $pos],
            $this->adminHeaders($admin));
    }

    private function move(string $admin, int $memberId, int $parentId, string $pos)
    {
        return $this->postJson('/api/v1/admin/genealogy/move',
            ['member_id' => $memberId, 'parent_id' => $parentId, 'position' => $pos],
            $this->adminHeaders($admin));
    }

    public function testPreviewIsReadOnlyAndValid(): void
    {
        [$ownerData, , $aId, $bId, $cId] = $this->buildTree();

        $res = $this->preview($ownerData, $cId, $bId, 'left')->assertOk();

        $this->assertTrue($res->json('data.valid'));
        $this->assertSame(1, $res->json('data.affected_nodes')); // у C нет детей
        $this->assertNotNull($res->json('data.after.path'));
        // Превью НЕ изменило БД: C всё ещё под A.
        $this->assertSame($aId, Member::find($cId)->parent_id);
    }

    public function testMoveReparentsMember(): void
    {
        [$ownerData, , , $bId, $cId] = $this->buildTree();

        $this->move($ownerData, $cId, $bId, 'left')->assertOk();

        $c = Member::find($cId);
        $this->assertSame($bId, $c->parent_id);
        $this->assertSame('left', $c->position);
        // Путь начинается от корня и заканчивается на самом участнике.
        $this->assertStringEndsWith((string) $cId, (string) $c->path);
        $this->assertStringContainsString((string) $bId, (string) $c->path);
    }

    public function testMoveSubtreeRepathsDescendants(): void
    {
        [$ownerData, , $aId, $bId, $cId] = $this->buildTree();

        // Переносим A (с ребёнком C) под B.right — затрагивает 2 узла.
        $res = $this->move($ownerData, $aId, $bId, 'right')->assertOk();
        $this->assertSame(2, $res->json('data.after.affected_nodes'));

        $a = Member::find($aId);
        $c = Member::find($cId);
        $this->assertSame($bId, $a->parent_id);
        $this->assertSame($aId, $c->parent_id, 'C остаётся ребёнком A');
        // C.path пересчитан вниз по новой цепочке: …B…A…C.
        $this->assertStringContainsString((string) $bId, (string) $c->path);
        $this->assertStringContainsString((string) $aId, (string) $c->path);
        $this->assertStringEndsWith((string) $cId, (string) $c->path);
    }

    public function testCannotMoveIntoOwnSubtree(): void
    {
        [$ownerData, , $aId, , $cId] = $this->buildTree();

        // A под C (C — потомок A) → цикл.
        $this->preview($ownerData, $aId, $cId, 'left')->assertOk()->assertJsonPath('data.valid', false);
        $this->move($ownerData, $aId, $cId, 'left')->assertStatus(422);
        // БД не тронута.
        $this->assertNotSame($cId, Member::find($aId)->parent_id);
    }

    public function testCannotMoveRoot(): void
    {
        [$ownerData, $ownerId, $aId] = $this->buildTree();

        $this->preview($ownerData, $ownerId, $aId, 'left')->assertOk()->assertJsonPath('data.valid', false);
        $this->move($ownerData, $ownerId, $aId, 'left')->assertStatus(422);
    }

    public function testCannotMoveIntoOccupiedSlot(): void
    {
        [$ownerData, $ownerId, $aId, , $cId] = $this->buildTree();

        // owner.left занят A → перенос C в owner.left запрещён.
        $this->preview($ownerData, $cId, $ownerId, 'left')->assertOk()->assertJsonPath('data.valid', false);
        $this->move($ownerData, $cId, $ownerId, 'left')->assertStatus(422);
        $this->assertSame($aId, Member::query()->where('parent_id', $ownerId)->where('position', 'left')->value('id'));
    }

    public function testMoveWritesAuditLog(): void
    {
        [$ownerData, $ownerId, , $bId, $cId] = $this->buildTree();

        $this->move($ownerData, $cId, $bId, 'left')->assertOk();

        $entry = AdminAuditLog::query()->where('action', 'placement.move')->where('entity_id', $cId)->first();
        $this->assertNotNull($entry);
        $this->assertSame('member', $entry->entity_type);
        $this->assertSame($ownerId, $entry->actor_member_id);
    }

    public function testOnlyOwnerCanMove(): void
    {
        [$ownerData, , , $bId, $cId] = $this->buildTree();
        [$financeData] = $this->registerTg(310, $this->memberByTg(300)->ref_code, 'Fin');
        $this->grantRole(310, 'finance');
        [$partnerData] = $this->registerTg(311, $this->memberByTg(300)->ref_code, 'P');

        // finance и без роли — 403 и на preview, и на move.
        $this->preview($financeData, $cId, $bId, 'left')->assertStatus(403);
        $this->move($financeData, $cId, $bId, 'left')->assertStatus(403);
        $this->preview($partnerData, $cId, $bId, 'left')->assertStatus(403);
        $this->move($partnerData, $cId, $bId, 'left')->assertStatus(403);
    }
}
