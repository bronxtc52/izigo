<?php

namespace Modules\Calculator\V2\Contracts;

use Carbon\CarbonImmutable;
use Modules\Calculator\V2\Domain\Policy\AccountRules;
use Modules\Calculator\V2\Domain\Policy\AwardRule;
use Modules\Calculator\V2\Domain\Policy\CalibrationRule;
use Modules\Calculator\V2\Domain\Policy\GlobalPoolRule;
use Modules\Calculator\V2\Domain\Policy\LeadershipRule;
use Modules\Calculator\V2\Domain\Policy\ReferralRule;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Domain\Policy\StatusRule;
use Modules\Calculator\V2\Domain\Policy\TierRule;

/**
 * V2: доменный объект версии политики (полный план MH: деньги — integer USD-центы,
 * ставки — integer basis points, PV-пороги — integer). Immutable read-model строки
 * v2_policy_versions; собирается ТОЛЬКО через PolicyV2Factory (T01 — единственный
 * владелец типа, amendments MF-5). Все остальные задачи потребляют объект через
 * {@see PolicyVersionResolver} и не заводят собственных типов политики.
 *
 * versionId()/configHash() ОБЯЗАНЫ попадать в снапшоты расчётов T04/T06–T11
 * (provenance каждого денежного расчёта).
 */
class PolicyV2
{
    /**
     * @param TierRule[] $tiers в порядке возрастания min_pv
     * @param array<string, StatusRule> $statuses ключ — код статуса, порядок ordinal 0..11
     */
    public function __construct(
        private readonly int $versionId,
        private readonly string $versionCode,
        private readonly int $schemaVersion,
        private readonly string $configHash,
        private readonly ?CarbonImmutable $validFrom,
        private readonly ?CarbonImmutable $validTo,
        private readonly array $raw,
        private readonly string $currency,
        private readonly int $kztRate,
        private readonly string $timezone,
        private readonly array $tiers,
        private readonly array $statuses,
        private readonly ReferralRule $referral,
        private readonly LeadershipRule $leadership,
        private readonly GlobalPoolRule $globalPool,
        private readonly AwardRule $award,
        private readonly int $graceClientToConsultantDays,
        private readonly AccountRules $accounts,
        private readonly CalibrationRule $calibration,
        private readonly bool $rankForever,
    ) {
    }

    // --- provenance (обязательны в снапшотах T04/T06-T11) ---

    public function versionId(): int
    {
        return $this->versionId;
    }

    public function configHash(): string
    {
        return $this->configHash;
    }

    public function versionCode(): string
    {
        return $this->versionCode;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function validFrom(): ?CarbonImmutable
    {
        return $this->validFrom;
    }

    public function validTo(): ?CarbonImmutable
    {
        return $this->validTo;
    }

    /** Полный сырой документ конфига (для снапшотов/отладки; НЕ для расчётов). */
    public function raw(): array
    {
        return $this->raw;
    }

    // --- meta ---

    public function currency(): string
    {
        return $this->currency;
    }

    public function kztRate(): int
    {
        return $this->kztRate;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    // --- секции плана ---

    /** @return TierRule[] */
    public function tiers(): array
    {
        return $this->tiers;
    }

    public function tierByCode(string $code): TierRule
    {
        foreach ($this->tiers as $tier) {
            if ($tier->code === $code) {
                return $tier;
            }
        }

        throw new \InvalidArgumentException("Неизвестный тир: {$code}");
    }

    /** Тир по накопленному personal PV (null = ниже START). */
    public function tierForPv(int $personalPv): ?TierRule
    {
        $match = null;
        foreach ($this->tiers as $tier) {
            if ($personalPv >= $tier->minPv
                && ($tier->maxPvExclusive === null || $personalPv < $tier->maxPvExclusive)) {
                $match = $tier;
            }
        }

        return $match;
    }

    /** @return array<string, StatusRule> ключ — код статуса, порядок ordinal 0..11 */
    public function statuses(): array
    {
        return $this->statuses;
    }

    public function statusByCode(StatusCode|string $code): StatusRule
    {
        $key = $code instanceof StatusCode ? $code->value : $code;
        if (!isset($this->statuses[$key])) {
            throw new \InvalidArgumentException("Неизвестный статус: {$key}");
        }

        return $this->statuses[$key];
    }

    public function statusByOrdinal(int $ordinal): StatusRule
    {
        foreach ($this->statuses as $status) {
            if ($status->ordinal === $ordinal) {
                return $status;
            }
        }

        throw new \InvalidArgumentException("Нет статуса с ordinal {$ordinal}");
    }

    public function referral(): ReferralRule
    {
        return $this->referral;
    }

    public function leadership(): LeadershipRule
    {
        return $this->leadership;
    }

    public function globalPool(): GlobalPoolRule
    {
        return $this->globalPool;
    }

    public function award(): AwardRule
    {
        return $this->award;
    }

    public function graceClientToConsultantDays(): int
    {
        return $this->graceClientToConsultantDays;
    }

    public function accounts(): AccountRules
    {
        return $this->accounts;
    }

    public function calibration(): CalibrationRule
    {
        return $this->calibration;
    }

    /** Ранг навсегда (DEC-020): достигнутый статус не понижается. */
    public function rankForever(): bool
    {
        return $this->rankForever;
    }
}
