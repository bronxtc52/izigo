<?php

namespace Tests\Verification;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberBonusLine;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\Services\MemberService;
use Tests\TestCase;

/**
 * UI-correctness аудит веб-админки (Layer A): что админ ВИДИТ через API == вычисленная правда.
 * Сеется детерминированное бинарное дерево через ЖИВОЙ путь (MemberService::registerTelegram +
 * ActivationService::activate), затем дёргаются admin-эндпоинты так же, как фронт (Bearer Sanctum).
 *
 * НИКОГДА не прод: жёсткая привязка к izigo_v_ui (assert в setUp). Прод-код не меняется.
 */
class AdminUiDisplayTest extends TestCase
{
    use RefreshDatabase;

    private MemberService $members;
    private ActivationService $activation;

    /** root(owner) -> [child L active, child2 R active]; child L -> grandchild L registered. */
    private Member $root;
    private Member $childL;
    private Member $childR;
    private Member $grandL;

    protected function setUp(): void
    {
        parent::setUp();

        $db = DB::connection()->getDatabaseName();
        $this->assertSame('izigo_v_ui', $db, "Тест должен идти ТОЛЬКО против izigo_v_ui, а не {$db}");

        config(['calculator.telegram_bot_token' => '123456:TEST_BOT_TOKEN']);
        config(['app.locale' => 'ru']); // пакеты резолвятся в Bronze/Silver/Gold (lang ru)

        $this->members = app(MemberService::class);
        $this->activation = app(ActivationService::class);

        $this->seedTree();
    }

    private function seedTree(): void
    {
        // Вершина сети: parent_id NULL (manual placement без parent → корень).
        $this->root = $this->members->registerTelegram(900, 'Owner', 'owner');
        $this->giveOwner($this->root);
        $this->activation->activate($this->root->id, 2, 'act-root');

        // child L под root, левая нога; активирован пакетом 1 → реферал спонсору (root).
        $this->childL = $this->members->registerTelegram(
            901, 'ChildL', 'childl', $this->root->ref_code, null, $this->root->ref_code, 'left',
        );
        $this->activation->activate($this->childL->id, 1, 'act-childL');

        // child R под root, правая нога; активирован пакетом 3.
        $this->childR = $this->members->registerTelegram(
            902, 'ChildR', 'childr', $this->root->ref_code, null, $this->root->ref_code, 'right',
        );
        $this->activation->activate($this->childR->id, 3, 'act-childR');

        // grandchild под childL, левая нога; НЕ активирован → status registered.
        $this->grandL = $this->members->registerTelegram(
            903, 'GrandL', 'grandl', $this->childL->ref_code, null, $this->childL->ref_code, 'left',
        );

        // refresh из БД (placement мог проставить parent/position/path).
        $this->root->refresh();
        $this->childL->refresh();
        $this->childR->refresh();
        $this->grandL->refresh();
    }

    private function giveOwner(Member $m): void
    {
        $roleId = \Modules\Calculator\Models\Role::where('name', 'owner')->value('id');
        $m->roles()->syncWithoutDetaching([$roleId => ['leader_scope_member_id' => null]]);
    }

    private function adminHeaders(Member $m): array
    {
        $token = $m->createToken('verify-admin')->plainTextToken;

        return ['X-Requested-With' => 'XMLHttpRequest', 'Authorization' => 'Bearer ' . $token];
    }

    // ====================== GENEALOGY ======================

    public function test_genealogy_tree_matches_seeded_topology(): void
    {
        $res = $this->getJson('/api/v1/admin/genealogy', $this->adminHeaders($this->root))->assertOk();

        $tree = $res->json('data.tree');

        // Вершина = root.
        $this->assertSame($this->root->id, $tree['id']);
        $this->assertSame('Owner', $tree['name']);
        $this->assertSame('active', $tree['status']);
        // package_id присутствует в каждом узле; ФИКС: добавлено резолвнутое имя пакета.
        $this->assertArrayHasKey('package_id', $tree);
        $this->assertSame(2, $tree['package_id']);
        $this->assertSame('Silver', $tree['package']);

        // Дети упорядочены left раньше right.
        $children = $tree['children'];
        $this->assertCount(2, $children);
        $this->assertSame('left', $children[0]['position']);
        $this->assertSame('right', $children[1]['position']);
        $this->assertSame($this->childL->id, $children[0]['id']);
        $this->assertSame($this->childR->id, $children[1]['id']);
        $this->assertSame(1, $children[0]['package_id']);
        $this->assertSame(3, $children[1]['package_id']);
        $this->assertSame('Bronze', $children[0]['package']);
        $this->assertSame('Gold', $children[1]['package']);

        // grandchild под childL, registered, package_id null → package тоже null.
        $grand = $children[0]['children'];
        $this->assertCount(1, $grand);
        $this->assertSame($this->grandL->id, $grand[0]['id']);
        $this->assertSame('registered', $grand[0]['status']);
        $this->assertNull($grand[0]['package_id']);
        $this->assertNull($grand[0]['package']);

        // Cross-check vs actual rows.
        foreach ([$this->root, $this->childL, $this->childR] as $m) {
            $this->assertSame($m->status, Member::find($m->id)->status);
        }
    }

    public function test_genealogy_from_given_root(): void
    {
        $res = $this->getJson("/api/v1/admin/genealogy?root_id={$this->childL->id}", $this->adminHeaders($this->root))->assertOk();
        $this->assertSame($this->childL->id, $res->json('data.tree.id'));
        $this->assertSame($this->grandL->id, $res->json('data.tree.children.0.id'));
    }

    public function test_genealogy_depth_cap_truncated_flag(): void
    {
        // Линейная цепочка глубже GENEALOGY_MAX_DEPTH(6). Каждый следующий — ребёнок
        // предыдущего (parentRef=предыдущий), позиция left. Спуск по parent_id из ответа.
        $tgBase = 950;
        $parent = $this->root;
        $chainIds = [];
        for ($i = 0; $i < 8; $i++) {
            $m = $this->members->registerTelegram(
                $tgBase + $i, "Deep{$i}", "deep{$i}", $parent->ref_code, null, $parent->ref_code, 'left',
            );
            $m->refresh();
            $chainIds[] = $m->id;
            $parent = $m;
        }

        // Цепочка из 8 узлов гарантирует placement-путь глубже cap=6.
        $this->assertGreaterThanOrEqual(8, count($chainIds));

        $res = $this->getJson('/api/v1/admin/genealogy', $this->adminHeaders($this->root))->assertOk();
        $tree = $res->json('data.tree');

        // Любой узел с truncated=true должен реально иметь потомков в БД И находиться на cap=6.
        $maxDepth = $this->assertTruncationAtCap($tree, 0);
        $this->assertGreaterThanOrEqual(6, $maxDepth, 'Дерево должно достигать глубины cap (6)');
    }

    /**
     * Рекурсивно проверяет инвариант обрезки: на глубине < 6 узлы раскрыты; ровно на
     * глубине 6 truncated отражает наличие потомков (узел не раскрыт). Возвращает
     * максимальную встреченную глубину раскрытого узла.
     */
    private function assertTruncationAtCap(array $node, int $depth): int
    {
        if ($depth >= 6) {
            // На cap: детей в ответе нет; truncated == есть ли потомки в БД.
            $this->assertSame([], $node['children'], "Узел #{$node['id']} на глубине {$depth} не должен раскрывать детей");
            $hasKids = Member::where('parent_id', $node['id'])->exists();
            $this->assertSame($hasKids, (bool) ($node['truncated'] ?? false),
                "truncated на #{$node['id']} должен == наличию потомков в БД");

            return $depth;
        }

        // До cap: truncated не выставляется, дети раскрыты.
        $this->assertArrayNotHasKey('truncated', $node, "До cap truncated не выставляется (#{$node['id']})");
        $max = $depth;
        foreach ($node['children'] as $child) {
            $max = max($max, $this->assertTruncationAtCap($child, $depth + 1));
        }

        return $max;
    }

    // ====================== MEMBERS LIST / CARD ======================

    public function test_members_list_fields(): void
    {
        $res = $this->getJson('/api/v1/admin/members', $this->adminHeaders($this->root))->assertOk();
        $rows = collect($res->json('data.data'))->keyBy('member_id') ?? null;
        // listMembers использует ключ 'id' (не member_id) — проверим реальную форму.
        $rows = collect($res->json('data.data'))->keyBy('id');

        $rootRow = $rows->get($this->root->id);
        $this->assertSame('Owner', $rootRow['name']);
        $this->assertSame('active', $rootRow['status']);
        $this->assertArrayHasKey('package_id', $rootRow);
        // ФИКС: рядом с сырым package_id теперь резолвнутое имя пакета (как в отчёте «Пользователи»).
        $this->assertSame(2, $rootRow['package_id']);
        $this->assertIsInt($rootRow['package_id']);
        $this->assertSame('Silver', $rootRow['package']);
        $this->assertArrayHasKey('sponsor_id', $rootRow);
        $this->assertArrayHasKey('rank', $rootRow);
    }

    public function test_member_card_fields_and_branch(): void
    {
        $res = $this->getJson("/api/v1/admin/members/{$this->childL->id}", $this->adminHeaders($this->root))->assertOk();

        $m = $res->json('data.member');
        $this->assertSame($this->childL->id, $m['id']);
        $this->assertSame('ChildL', $m['name']);
        $this->assertSame('active', $m['status']);
        // ФИКС: фронт MemberCard.js теперь рендерит имя пакета (m.package), package_id оставлен.
        $this->assertSame(1, $m['package_id']);
        $this->assertIsInt($m['package_id']);
        $this->assertSame('Bronze', $m['package']);
        $this->assertSame($this->root->id, $m['sponsor_id']);
        $this->assertArrayHasKey('ref_code', $m);
        $this->assertArrayHasKey('parent_id', $m);
        $this->assertArrayHasKey('position', $m);

        // branch — дерево; корень ветки = сам участник.
        $branch = $res->json('data.branch');
        $this->assertSame('ChildL', $branch['name']);
    }

    // ====================== WALLET / BALANCES ======================

    public function test_member_wallet_matches_wallet_row(): void
    {
        $w = MemberWallet::where('member_id', $this->root->id)->firstOrFail();

        $res = $this->getJson("/api/v1/admin/members/{$this->root->id}/wallet", $this->adminHeaders($this->root))->assertOk();

        $this->assertSame((int) $w->available_cents, $res->json('data.available_cents'));
        $this->assertSame((int) $w->held_cents, $res->json('data.held_cents'));
        $this->assertSame((int) $w->clawback_debt_cents, $res->json('data.clawback_debt_cents'));

        // Owner заработал реферал с активаций личников (childL pkg1, childR pkg3) > 0.
        $this->assertGreaterThan(0, $res->json('data.available_cents'));
    }

    public function test_reports_balances_match_ledger_wallet(): void
    {
        $res = $this->getJson('/api/v1/admin/reports/balances', $this->adminHeaders($this->root))->assertOk();

        $wallets = MemberWallet::all()->keyBy('member_id');
        $rows = collect($res->json('data.data'))->keyBy('member_id');

        foreach ($wallets as $memberId => $w) {
            $this->assertSame((int) $w->available_cents, $rows->get($memberId)['available_cents']);
            $this->assertSame((int) $w->held_cents, $rows->get($memberId)['held_cents']);
        }

        // Итог == сумма кошельков.
        $this->assertSame((int) $wallets->sum('available_cents'), $res->json('data.totals.available_cents'));
        $this->assertSame((int) $wallets->sum('held_cents'), $res->json('data.totals.held_cents'));
    }

    // ====================== BONUS EXPENSE (snapshot vs period) ======================

    public function test_bonus_expense_type_breakdown_matches_bonus_lines(): void
    {
        $res = $this->getJson('/api/v1/admin/reports/bonus-expense', $this->adminHeaders($this->root))->assertOk();

        $byType = collect($res->json('data.by_type_snapshot'))->keyBy('type');

        // Каждый тип в снимке == сумма MemberBonusLine этого типа * 100 (центы).
        foreach (['binary', 'referral', 'leader', 'rank'] as $type) {
            $expected = (int) round(((float) MemberBonusLine::where('type', $type)->sum('amount')) * 100);
            $this->assertSame($expected, $byType[$type]['amount_cents'], "by_type_snapshot[{$type}] != сумма MemberBonusLine");
        }

        // total_expense_cents == net по ledger COMMISSION_EXPENSE (company, member_id NULL).
        $ledgerNet = (int) LedgerEntry::query()
            ->where('account_type', LedgerService::ACC_COMMISSION_EXPENSE)
            ->whereNull('member_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='debit' THEN amount_cents ELSE -amount_cents END),0) AS net")
            ->value('net');
        $this->assertSame($ledgerNet, $res->json('data.total_expense_cents'));
        $this->assertGreaterThan(0, $res->json('data.total_expense_cents'));
    }

    public function test_bonus_expense_snapshot_vs_period_reconciliation(): void
    {
        // Документируем ИЗВЕСТНЫЙ дизайн: by_type_snapshot НЕ фильтруется периодом, а
        // total_expense_cents — ДА. Запрашиваем период В БУДУЩЕМ (нет проводок) → total 0,
        // но снимок по типам остаётся ненулевым. Значит сумма строк != итог (by design).
        $future = now()->addYears(5)->format('Y-m-d');
        $res = $this->getJson("/api/v1/admin/reports/bonus-expense?from={$future}&to={$future}", $this->adminHeaders($this->root))->assertOk();

        $this->assertSame(0, $res->json('data.total_expense_cents'), 'Период в будущем → расход 0');
        $snapshotSum = collect($res->json('data.by_type_snapshot'))->sum('amount_cents');
        $this->assertGreaterThan(0, $snapshotSum, 'Снимок по типам игнорирует период → остаётся > 0');
        // Итог != сумма строк → таблица фронта может ввести в заблуждение при выбранном периоде.
        $this->assertNotSame($res->json('data.total_expense_cents'), $snapshotSum);
    }

    // ====================== NEGATIVE AUTH (deny-by-default) ======================

    public function test_no_token_returns_401(): void
    {
        $this->getJson('/api/v1/admin/genealogy', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
        $this->getJson('/api/v1/admin/members', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
        $this->getJson('/api/v1/admin/reports/balances', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
    }

    public function test_roleless_member_returns_403(): void
    {
        // grandL зарегистрирован, без ролей и не owner.
        $headers = $this->adminHeaders($this->grandL);
        $this->getJson('/api/v1/admin/members', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/genealogy', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/reports/balances', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/reports/bonus-expense', $headers)->assertStatus(403);
    }
}
