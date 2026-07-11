<?php

namespace Modules\Calculator\V2\Domain\Policy;

use Carbon\CarbonImmutable;
use Modules\Calculator\Models\PolicyVersion;
use Modules\Calculator\V2\Contracts\PolicyV2;

/**
 * T01: сборка immutable read-model {@see PolicyV2} из строки v2_policy_versions.
 * Предполагает документ, прошедший {@see \Modules\Calculator\V2\Services\PolicyConfigValidator}
 * (валидатор — единственные ворота записи конфига в БД).
 */
class PolicyV2Factory
{
    public function fromModel(PolicyVersion $version): PolicyV2
    {
        $doc = $version->config;
        if (!is_array($doc)) {
            throw new \InvalidArgumentException("PolicyVersion #{$version->id}: пустой config");
        }

        return new PolicyV2(
            versionId: (int) $version->id,
            versionCode: (string) $version->code,
            schemaVersion: (int) $version->schema_version,
            configHash: (string) $version->config_hash,
            validFrom: $version->valid_from ? CarbonImmutable::parse($version->valid_from) : null,
            validTo: $version->valid_to ? CarbonImmutable::parse($version->valid_to) : null,
            raw: $doc,
            currency: $doc['meta']['currency'],
            kztRate: $doc['meta']['kzt_rate'],
            timezone: $doc['meta']['timezone'],
            tiers: $this->tiers($doc['tiers']),
            statuses: $this->statuses($doc['statuses']),
            referral: new ReferralRule(
                maxDepth: $doc['referral']['max_depth'],
                stopAtElite: $doc['referral']['stop_at_elite'],
                destination: $doc['referral']['destination'],
                trigger: $doc['referral']['trigger'],
            ),
            leadership: $this->leadership($doc['leadership']),
            globalPool: $this->globalPool($doc['global_pool']),
            award: new AwardRule(
                destination: $doc['award']['destination'],
                onRankJump: $doc['award']['on_rank_jump'],
                byStatusCents: $doc['award']['by_status_cents'],
                vpTranches: $doc['award']['vp_tranches'],
            ),
            graceClientToConsultantDays: $doc['grace']['client_to_consultant_days'],
            accounts: $this->accounts($doc['accounts']),
            calibration: new CalibrationRule(
                rateBp: $doc['calibration']['rate_bp'],
                mode: $doc['calibration']['mode'],
                base: $doc['calibration']['base'],
                include: $doc['calibration']['include'],
            ),
            rankForever: $doc['rank_forever'],
        );
    }

    /** @return TierRule[] */
    private function tiers(array $tiers): array
    {
        return array_map(static fn (array $tier) => new TierRule(
            code: $tier['code'],
            minPv: $tier['min_pv'],
            maxPvExclusive: $tier['max_pv_exclusive'],
            referralL1Bp: $tier['referral_rates_bp']['l1'],
            referralL2Bp: $tier['referral_rates_bp']['l2'],
        ), array_values($tiers));
    }

    /** @return array<string, StatusRule> */
    private function statuses(array $statuses): array
    {
        $result = [];
        foreach (array_values($statuses) as $status) {
            $q = $status['qualification'];
            $variantsCfg = $q['variants'] ?? null;

            $variants = [];
            if ($variantsCfg !== null) {
                foreach ($variantsCfg['options'] as $option) {
                    $variants[] = new QualificationVariantRule(
                        code: $option['code'],
                        anchorCount: $option['anchor_count'],
                        supportCount: $option['support_count'],
                        comparator: $option['comparator'],
                        distinctRootBranches: $option['distinct_root_branches'],
                    );
                }
            }

            $rule = new StatusRule(
                code: StatusCode::from($status['code']),
                ordinal: $status['ordinal'],
                binaryRateBp: $status['binary_rate_bp'],
                monthlyCapCents: $status['monthly_cap_cents'],
                halfMonthCapCents: $status['half_month_cap_cents'],
                eliteLeadershipDepth: $status['elite_leadership_depth'],
                personalPurchasePvMin: $q['personal_purchase_pv_min'] ?? null,
                qualifiedReferralsMin: $q['qualified_referrals_min'] ?? null,
                referralPvMin: $q['referral_pv_min'] ?? null,
                smallBranchPvMin: $q['small_branch_pv_min'] ?? null,
                directReferralsMin: $q['direct_referrals_min'] ?? null,
                anchorRank: isset($variantsCfg['anchor_rank']) ? StatusCode::from($variantsCfg['anchor_rank']) : null,
                supportRank: isset($variantsCfg['support_rank']) && $variantsCfg['support_rank'] !== null
                    ? StatusCode::from($variantsCfg['support_rank'])
                    : null,
                variants: $variants,
            );
            $result[$rule->code->value] = $rule;
        }

        return $result;
    }

    private function leadership(array $leadership): LeadershipRule
    {
        return new LeadershipRule(
            eligibilityStatusMin: StatusCode::from($leadership['eligibility_status_min']),
            base: $leadership['base'],
            rankGapBlockOrdinalDiff: $leadership['rank_gap_block_ordinal_diff'],
            startRatesBp: $leadership['tiers']['START']['rates_bp'],
            businessRatesBp: $leadership['tiers']['BUSINESS']['rates_bp'],
            eliteRatesBp: $leadership['tiers']['ELITE']['rates_bp'],
            eliteMaxDepth: $leadership['tiers']['ELITE']['max_depth'],
        );
    }

    private function globalPool(array $pool): GlobalPoolRule
    {
        $pools = [];
        foreach (array_values($pool['pools']) as $p) {
            $pools[$p['status']] = ['rate_bp' => $p['rate_bp'], 'one_share_pv_min' => $p['one_share_pv_min']];
        }

        return new GlobalPoolRule(
            pools: $pools,
            maxShares: $pool['max_shares'],
            memberCapBp: $pool['member_cap_bp'],
            remainder: $pool['remainder'],
            accrual: $pool['accrual'],
            payout: $pool['payout'],
            quarterMode: $pool['quarter_mode'],
            inheritsLowerPools: $pool['inherits_lower_pools'],
            includePersonalPv: $pool['include_personal_pv'],
        );
    }

    private function accounts(array $accounts): AccountRules
    {
        return new AccountRules(
            osWithdrawable: $accounts['os']['withdrawable'],
            osMaxOrderPaymentShareBp: $accounts['os']['max_order_payment_share_bp'],
            osLotLifetimeDays: $accounts['os']['lot_lifetime_days'],
            osOnExpiry: $accounts['os']['on_expiry'],
            nsTransferDays: $accounts['ns']['transfer_days'],
            nsTransferTo: $accounts['ns']['transfer_to'],
            bsWithdrawable: $accounts['bs']['withdrawable'],
            bsPurchasable: $accounts['bs']['purchasable'],
            bsLotLifetimeDays: $accounts['bs']['lot_lifetime_days'],
            bsOnExpiry: $accounts['bs']['on_expiry'],
            lotConsumption: $accounts['lot_consumption'],
            internalFundingFullBv: $accounts['internal_funding_full_bv'],
        );
    }
}
