<?php

namespace Modules\Calculator\V2\Domain\Bonus;

/**
 * T08: результат расчёта лидерского для ОДНОЙ пары (source, receiver) на глубине depth —
 * либо начисление (STATUS_ACCRUED, amount>0), либо аудит-исключение (STATUS_EXCLUDED +
 * reason). Чистое значение, персистится LeadershipBonusService в v2_leadership_bonus_lines.
 *
 * Причины исключения (DEC-030 / CAL-LED-001):
 *  - BELOW_MANAGER    — ранг получателя < MANAGER (пропуск, depth инкрементится, без компрессии);
 *  - RANK_GAP_BLOCK   — узел поддерева source..receiver с ordinal >= receiver+gap блокирует ветвь
 *                       ($blockingMemberId — виновник), бонус НЕ передаётся выше;
 *  - DEPTH_NOT_ALLOWED — depth за пределом разрешённой глубины тира/ранга получателя;
 *  - RATE_ZERO        — ставка на этой глубине равна нулю (или тир не резолвится).
 */
final class LeadershipLine
{
    public const STATUS_ACCRUED = 'accrued';
    public const STATUS_EXCLUDED = 'excluded';

    public const REASON_BELOW_MANAGER = 'BELOW_MANAGER';
    public const REASON_RANK_GAP_BLOCK = 'RANK_GAP_BLOCK';
    public const REASON_DEPTH_NOT_ALLOWED = 'DEPTH_NOT_ALLOWED';
    public const REASON_RATE_ZERO = 'RATE_ZERO';

    private function __construct(
        public readonly int $receiverMemberId,
        public readonly int $depth,
        public readonly ?string $receiverRankCode,
        public readonly ?string $receiverTier,
        public readonly int $rateBp,
        public readonly int $baseCents,
        public readonly int $amountCents,
        public readonly string $status,
        public readonly ?string $exclusionReason,
        public readonly ?int $blockingMemberId,
    ) {
    }

    public static function accrued(
        LeadershipChainNode $receiver,
        int $depth,
        int $rateBp,
        int $baseCents,
        int $amountCents,
    ): self {
        return new self(
            $receiver->memberId,
            $depth,
            $receiver->rankCode,
            $receiver->tier,
            $rateBp,
            $baseCents,
            $amountCents,
            self::STATUS_ACCRUED,
            null,
            null,
        );
    }

    public static function excluded(
        LeadershipChainNode $receiver,
        int $depth,
        string $reason,
        int $baseCents,
        ?int $blockingMemberId = null,
    ): self {
        return new self(
            $receiver->memberId,
            $depth,
            $receiver->rankCode,
            $receiver->tier,
            0,
            $baseCents,
            0,
            self::STATUS_EXCLUDED,
            $reason,
            $blockingMemberId,
        );
    }

    public function isAccrued(): bool
    {
        return $this->status === self::STATUS_ACCRUED;
    }
}
