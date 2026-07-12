<?php

namespace Modules\Calculator\V2\Services\Awards;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\PaidOrderV2Step;

/**
 * T10: шаг пайплайна пост-оплаты — грант квалификационных наград. Регистрируется
 * ПОСЛЕ StatusesStep T05 (нужны свежие строки v2_rank_history покупателя и его
 * sponsor-аплайна, которые тот только что записал). Гейтится флагом mh_v2_awards
 * (deny-by-default): выключен => ни одной награды.
 *
 * Триггер наград — записи v2_rank_history, а не Laravel-событие (T05 события не
 * эмитит, возвращает список новоприсвоенных рангов внутри своего шага). Поэтому
 * шаг идемпотентно ДОБИРАЕТ недостающие entitlement'ы по истории рангов для
 * затронутого заказом множества (покупатель + его sponsor-аплайн — ровно те, кого
 * переоценивал RankEvaluationService::evaluateAffectedUpline). Повтор безопасен
 * (unique(member,code,stage) + ledger idempotency key). Работает под тем же
 * advisory-lock, что и весь пайплайн (взят markPaid оплаты).
 */
class AwardsStep implements PaidOrderV2Step
{
    public const FLAG = 'mh_v2_awards';

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly QualificationAwardService $awards,
    ) {
    }

    public function handle(int $orderId): void
    {
        if (! $this->flags->isEnabled(self::FLAG)) {
            return;
        }

        // Снапшот заказа (owner volume-слоя T03): покупатель + момент оплаты.
        $row = DB::table('v2_order_volume_snapshots')->where('order_id', $orderId)->first();
        if ($row === null) {
            return; // volume-слой не заснял заказ — наградам нечего триггерить
        }

        $buyerId = (int) $row->member_id;
        $paidAt = CarbonImmutable::parse($row->paid_at);

        // Покупатель + весь sponsor-аплайн: покупка на глубине N меняет малую ветку
        // всех предков => любой из них мог получить новый ранг (как в T05).
        foreach ($this->uplineIncluding($buyerId) as $memberId) {
            $this->awards->reconcileMemberFromRankHistory($memberId, $paidAt);
        }
    }

    /** Аплайн по sponsor_id включая самого участника (крошечный прод — простой цикл). */
    private function uplineIncluding(int $memberId): array
    {
        $chain = [];
        $node = $memberId;
        $seen = [];
        while ($node !== null && ! isset($seen[$node])) {
            $seen[$node] = true;
            $chain[] = $node;
            $node = DB::table('members')->where('id', $node)->value('sponsor_id');
            $node = $node === null ? null : (int) $node;
        }

        return $chain;
    }
}
