<?php

namespace Modules\Calculator\V2\Services\Read;

use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\AwardEntitlement;
use Modules\Calculator\V2\Services\Status\StatusReadService;

/**
 * mh-full-plan T14: read-проекция квалификационных наград для Mini App.
 *
 * Каталог наград (суммы USD-центами) — строго из PolicyVersion T01 (byStatusCents +
 * VP-транши); состояние каждой награды — из ФАКТИЧЕСКИХ entitlement'ов T10
 * (v2_award_entitlements, источник истины после мерджа T10, см. риск-заметку плана).
 * Для наград без entitlement состояние деривируется из истории достигнутых рангов:
 * при скачке через ранги видны ВСЕ пройденные ступени как earned (DEC-040).
 *
 * Награды деньгами на БС, выплата вручную by design — кнопок выплаты в Mini App нет,
 * только статус. Ранг навсегда → награды не отзываются (DEC-020/027): forfeited —
 * лишь статус-решение админа, начисление не удаляется.
 *
 * Деньги — integer USD-центы + строковое decimal; пустой каталог → пустой список.
 */
class AwardsReadService
{
    use CentsFormat;

    // UI-состояния награды (фронт локализует).
    public const STATE_LOCKED = 'locked';   // ранг не достигнут, entitlement'а нет
    public const STATE_EARNED = 'earned';    // ранг достигнут (DEC-040), грант ещё не проведён

    public function __construct(private readonly StatusReadService $reader)
    {
    }

    public function awards(int $memberId, PolicyV2 $policy): array
    {
        $award = $policy->award();
        $entitlements = $this->entitlementsByKey($memberId);
        $achieved = $this->achievedSet($memberId);

        $items = [];

        // Награды за статусы MANAGER..DIAMOND_DIRECTOR (один stage_no=1 на код).
        foreach ($award->byStatusCents as $statusCode => $cents) {
            $items[] = $this->item(
                statusCode: $statusCode,
                awardCode: $statusCode,
                stageNo: 1,
                amountCents: (int) $cents,
                entitlement: $entitlements[$statusCode . ':1'] ?? null,
                rankAchieved: isset($achieved[$statusCode]),
                trigger: AwardEntitlement::TRIGGER_RANK_ACHIEVED,
            );
        }

        // VP — три транша (stage 1 — достижение ранга, 2/3 — квалификации глобального).
        foreach ($award->vpTranches as $tranche) {
            $stage = (int) $tranche['sequence'];
            $ent = $entitlements[AwardEntitlement::CODE_VICE_PRESIDENT . ':' . $stage] ?? null;
            // earned только для этапа 1 (по рангу); этапы 2/3 — по глобальным квалификациям,
            // деривировать из ранга нельзя → без entitlement они locked.
            $rankAchieved = $stage === 1 && isset($achieved[StatusCode::VICE_PRESIDENT->value]);
            $items[] = $this->item(
                statusCode: StatusCode::VICE_PRESIDENT->value,
                awardCode: AwardEntitlement::CODE_VICE_PRESIDENT,
                stageNo: $stage,
                amountCents: (int) $tranche['amount_cents'],
                entitlement: $ent,
                rankAchieved: $rankAchieved,
                trigger: $tranche['trigger'] ?? null,
            );
        }

        // Порядок: по ordinal статуса, VP-транши по stage.
        usort($items, function ($a, $b) {
            $oa = StatusCode::from($a['status_code'])->ordinal();
            $ob = StatusCode::from($b['status_code'])->ordinal();

            return $oa <=> $ob ?: $a['stage_no'] <=> $b['stage_no'];
        });

        return ['destination' => $award->destination, 'items' => $items];
    }

    private function item(
        string $statusCode,
        string $awardCode,
        int $stageNo,
        int $amountCents,
        ?AwardEntitlement $entitlement,
        bool $rankAchieved,
        ?string $trigger,
    ): array {
        // Реальный entitlement T10 — источник истины; иначе дериватив по рангу.
        $state = $entitlement !== null
            ? $entitlement->status
            : ($rankAchieved ? self::STATE_EARNED : self::STATE_LOCKED);

        return [
            'award_code' => $awardCode,
            'status_code' => $statusCode,
            'stage_no' => $stageNo,
            'amount_cents' => $amountCents,
            'amount' => $this->centsToDecimal($amountCents),
            'state' => $state,
            'entitlement_status' => $entitlement?->status,
            'rank_achieved' => $rankAchieved,
            'trigger' => $trigger,
            'granted_at' => $entitlement?->granted_at?->toIso8601String(),
            'paid_at' => $entitlement?->paid_at?->toIso8601String(),
        ];
    }

    /** @return array<string, AwardEntitlement> ключ "award_code:stage_no" */
    private function entitlementsByKey(int $memberId): array
    {
        $out = [];
        foreach (AwardEntitlement::query()->where('member_id', $memberId)->get() as $e) {
            $out[$e->award_code . ':' . $e->stage_no] = $e;
        }

        return $out;
    }

    /** @return array<string, true> достигнутые ранги (для дериватива earned/locked) */
    private function achievedSet(int $memberId): array
    {
        $set = [];
        foreach ($this->reader->achievedRanks($memberId) as $row) {
            $set[$row['rank_code']] = true;
        }

        return $set;
    }
}
