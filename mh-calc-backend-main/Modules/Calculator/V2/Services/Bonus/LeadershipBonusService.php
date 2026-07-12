<?php

namespace Modules\Calculator\V2\Services\Bonus;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Member;
use Modules\Calculator\V2\Contracts\LedgerV2;
use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Contracts\StatusReader;
use Modules\Calculator\V2\Domain\Bonus\LeadershipCalculator;
use Modules\Calculator\V2\Domain\Bonus\LeadershipChainNode;
use Modules\Calculator\V2\Domain\Bonus\LeadershipLine;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\LeadershipBonusLine;

/**
 * T08 — оркестратор лидерского бонуса (CAL-LED-001) за период. Читает финализированные
 * строки структурной премии (net ПОСЛЕ капов и 60%-калибровки, DEC-029) через
 * LeadershipBaseSourceInterface, строит sponsor-цепочки и снапшоты ранга/тира на конец
 * периода (StatusReader — v2_rank_history/v2_tier_history as-of), гоняет чистый
 * калькулятор и upsert'ит строки в v2_leadership_bonus_lines по ключу
 * (source_structure_bonus_id, receiver_member_id). Начисления кредитуются на ОС
 * (кредит-лот 1 год) с idempotency-ключом v2:leadership:{sbId}:{receiverId}
 * (period-независимым — двойной прогон/двойной путь не задваивают деньги).
 *
 * Штатно шаг закрытия месяца (LeadershipCloseStep) вызывает runForPeriod СТРОГО после
 * шага 60%-пула T11 (DEC-053) — тогда net уже калиброван. Всё в DB::transaction;
 * повторный запуск — no-op (posted-строки не перезаписываются, ledger идемпотентен).
 * Работает под advisory-lock активаций (взят оркестратором закрытия периода T04).
 */
class LeadershipBonusService
{
    /** @var array<int, ?LeadershipChainNode> кэш снапшотов узлов на текущий прогон */
    private array $nodeCache = [];

    /** @var array<int, ?int> кэш sponsor_id (id → sponsor_id) */
    private array $sponsorMap = [];

    private bool $sponsorMapLoaded = false;

    public function __construct(
        private readonly PolicyVersionResolver $policyResolver,
        private readonly StatusReader $statusReader,
        private readonly LeadershipBaseSourceInterface $baseSource,
        private readonly LeadershipCalculator $calculator,
        private readonly \Modules\Calculator\V2\Services\Periods\PeriodService $periods,
        private readonly LedgerV2 $ledger,
    ) {
    }

    /**
     * Рассчитать и провести лидерский бонус периода. Идемпотентно.
     *
     * @return array{sources:int, accrued:int, posted:int, excluded:int, posted_cents:int}
     */
    public function runForPeriod(CalcPeriod $period): array
    {
        // Закрытый период неизменяем; внутри пайплайна period в статусе closing.
        $this->periods->assertOpen($period, allowClosing: true);

        $this->nodeCache = [];
        $this->loadSponsorMap();

        $rows = $this->baseSource->baseRowsForPeriod($period);
        if ($rows === []) {
            return ['sources' => 0, 'accrued' => 0, 'posted' => 0, 'excluded' => 0, 'posted_cents' => 0];
        }

        /** @var \Carbon\CarbonImmutable $asOf */
        $asOf = $period->ends_at; // снапшот ранга/тира на конец периода (ранг навсегда, DEC-020)
        $policy = $this->policyResolver->forDate($asOf);
        $rule = $policy->leadership();
        $osLotDays = $policy->accounts()->osLotLifetimeDays;
        $expiresAt = now()->addDays($osLotDays);

        $sources = 0;
        $accrued = 0;
        $posted = 0;
        $excluded = 0;
        $postedCents = 0;

        DB::transaction(function () use (
            $rows, $period, $policy, $rule, $asOf, $expiresAt,
            &$sources, &$accrued, &$posted, &$excluded, &$postedCents
        ) {
            foreach ($rows as $row) {
                $sourceId = $row['id'];
                $sourceMemberId = $row['member_id'];
                $baseCents = $row['net_cents'];

                $sourceNode = $this->node($sourceMemberId, $asOf, $policy);
                $chain = $this->buildChain($sourceMemberId, $asOf, $policy, $rule->eliteMaxDepth);
                if ($chain === []) {
                    continue; // корень цепочки — получателей нет
                }

                $sources++;
                $lines = $this->calculator->compute($sourceNode, $baseCents, $chain, $rule);

                foreach ($lines as $line) {
                    $result = $this->persistAndPost(
                        $period, $sourceId, $sourceMemberId, $baseCents, $policy, $line, $expiresAt
                    );
                    if ($result === 'posted') {
                        $posted++;
                        $accrued++;
                        $postedCents += $line->amountCents;
                    } elseif ($result === 'skipped_posted') {
                        $posted++;
                        $accrued++;
                        $postedCents += $line->amountCents;
                    } else {
                        $excluded++;
                    }
                }
            }
        });

        return [
            'sources' => $sources,
            'accrued' => $accrued,
            'posted' => $posted,
            'excluded' => $excluded,
            'posted_cents' => $postedCents,
        ];
    }

    /**
     * Upsert строки и (для начисления) проводка на ОС. Возвращает 'posted' | 'skipped_posted'
     * | 'excluded'.
     */
    private function persistAndPost(
        CalcPeriod $period,
        int $sourceId,
        int $sourceMemberId,
        int $baseCents,
        PolicyV2 $policy,
        LeadershipLine $line,
        \DateTimeInterface $expiresAt,
    ): string {
        $existing = LeadershipBonusLine::query()
            ->where('source_structure_bonus_id', $sourceId)
            ->where('receiver_member_id', $line->receiverMemberId)
            ->first();

        // Уже проведено — идемпотентный no-op (деньги не задваиваем).
        if ($existing !== null && $existing->status === LeadershipBonusLine::STATUS_POSTED) {
            return 'skipped_posted';
        }

        $isAccrued = $line->isAccrued() && $line->amountCents > 0;
        $key = sprintf('v2:leadership:%d:%d', $sourceId, $line->receiverMemberId);

        $attrs = [
            'period_id' => $period->id,
            'receiver_member_id' => $line->receiverMemberId,
            'source_member_id' => $sourceMemberId,
            'source_structure_bonus_id' => $sourceId,
            'depth' => $line->depth,
            'receiver_rank_key' => $line->receiverRankCode,
            'receiver_tier' => $line->receiverTier,
            'rate_bp' => $line->rateBp,
            'base_cents' => $baseCents,
            'amount_cents' => $line->amountCents,
            'status' => $isAccrued ? LeadershipBonusLine::STATUS_ACCRUED : LeadershipBonusLine::STATUS_EXCLUDED,
            'exclusion_reason' => $line->exclusionReason,
            'blocking_member_id' => $line->blockingMemberId,
            'policy_version_id' => $policy->versionId(),
            'ledger_tx_id' => $isAccrued ? $key : null,
            'explanation' => [
                'config_hash' => $policy->configHash(),
                'depth' => $line->depth,
                'receiver_rank' => $line->receiverRankCode,
                'receiver_tier' => $line->receiverTier,
                'rate_bp' => $line->rateBp,
                'base_cents' => $baseCents,
                'amount_cents' => $line->amountCents,
                'exclusion_reason' => $line->exclusionReason,
                'blocking_member_id' => $line->blockingMemberId,
            ],
        ];

        $model = $existing ?? new LeadershipBonusLine();
        $model->fill($attrs)->save();

        if (! $isAccrued) {
            return 'excluded';
        }

        // Проводка на ОС (кредит-лот 1 год). Идемпотентно по ключу (alreadyPosted).
        $this->ledger->credit(
            $line->receiverMemberId,
            LedgerV2::SUBACCOUNT_OS,
            $line->amountCents,
            $key,
            $expiresAt,
            LeadershipBonusLine::SOURCE_TYPE,
            $model->id,
        );

        $model->status = LeadershipBonusLine::STATUS_POSTED;
        $model->save();

        return 'posted';
    }

    /**
     * Sponsor-цепочка получателей от source.sponsor вверх, максимум $maxDepth узлов.
     * Защита от циклов (visited) — sponsor_id стабилен после активации (план T08).
     *
     * @return LeadershipChainNode[]
     */
    private function buildChain(int $sourceMemberId, \DateTimeInterface $asOf, PolicyV2 $policy, int $maxDepth): array
    {
        $chain = [];
        $visited = [$sourceMemberId => true];
        $current = $this->sponsorOf($sourceMemberId);

        while ($current !== null && count($chain) < $maxDepth) {
            if (isset($visited[$current])) {
                break; // цикл — обрываем
            }
            $visited[$current] = true;
            $chain[] = $this->node($current, $asOf, $policy);
            $current = $this->sponsorOf($current);
        }

        return $chain;
    }

    private function node(int $memberId, \DateTimeInterface $asOf, PolicyV2 $policy): LeadershipChainNode
    {
        if (array_key_exists($memberId, $this->nodeCache) && $this->nodeCache[$memberId] !== null) {
            return $this->nodeCache[$memberId];
        }

        $rankCode = $this->statusReader->rankAsOf($memberId, $asOf);
        $rankOrdinal = null;
        $eliteDepth = 0;
        if ($rankCode !== null) {
            $rankOrdinal = StatusCode::from($rankCode)->ordinal();
            $eliteDepth = $policy->statusByCode($rankCode)->eliteLeadershipDepth;
        }
        $tier = $this->statusReader->tierAsOf($memberId, $asOf);

        return $this->nodeCache[$memberId] = new LeadershipChainNode(
            $memberId,
            $rankCode,
            $rankOrdinal,
            $tier,
            $eliteDepth,
        );
    }

    private function sponsorOf(int $memberId): ?int
    {
        return $this->sponsorMap[$memberId] ?? null;
    }

    private function loadSponsorMap(): void
    {
        if ($this->sponsorMapLoaded) {
            return;
        }
        $this->sponsorMap = Member::query()
            ->whereNotNull('sponsor_id')
            ->pluck('sponsor_id', 'id')
            ->map(fn ($v) => (int) $v)
            ->all();
        $this->sponsorMapLoaded = true;
    }
}
