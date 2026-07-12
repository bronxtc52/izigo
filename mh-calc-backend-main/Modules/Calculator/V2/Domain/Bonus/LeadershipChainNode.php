<?php

namespace Modules\Calculator\V2\Domain\Bonus;

/**
 * T08: снапшот одного узла sponsor-цепочки (источник или потенциальный получатель),
 * вход чистого калькулятора CAL-LED-001. Без Eloquent — чистые значения из T05-снапшота
 * на период (rankAsOf/tierAsOf) + каталог статусов T01 (ordinal, elite_leadership_depth).
 *
 *  - $rankOrdinal null = ранга ещё нет (< MANAGER → BELOW_MANAGER, но узел всё равно
 *    участвует в depth и как «нижний» для rank-gap вышестоящих, без компрессии);
 *  - $tier null = ниже START (ставка не резолвится → RATE_ZERO);
 *  - $eliteMaxDepth — разрешённая ELITE-глубина ранга (Manager 1 … Sapphire/Diamond/VP 7),
 *    из StatusRule::eliteLeadershipDepth; для не-ELITE тира не используется.
 */
final class LeadershipChainNode
{
    public function __construct(
        public readonly int $memberId,
        public readonly ?string $rankCode,
        public readonly ?int $rankOrdinal,
        public readonly ?string $tier,
        public readonly int $eliteMaxDepth,
    ) {
    }
}
