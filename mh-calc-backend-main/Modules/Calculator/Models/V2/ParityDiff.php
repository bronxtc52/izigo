<?php

namespace Modules\Calculator\Models\V2;

use Illuminate\Database\Eloquent\Model;

/**
 * mh-full-plan T15: одна строка расхождения паритета (v2_parity_diffs).
 * classification — словарь задачи: match / mismatch / v2_only / plan_change.
 */
class ParityDiff extends Model
{
    protected $table = 'v2_parity_diffs';

    public $timestamps = false;

    public const CHECK_MONEY = 'money_conservation';
    public const CHECK_HELD = 'held_in_flight';
    public const CHECK_CLAWBACK = 'clawback_debt';
    public const CHECK_ACCRUED = 'accrued_income';
    public const CHECK_TREE = 'tree_composition';

    /** Обязано совпасть и совпало. */
    public const CLASS_MATCH = 'match';
    /** Обязано совпасть, но НЕ совпало — реальная проблема (блокирует accept). */
    public const CLASS_MISMATCH = 'mismatch';
    /** Механика V2 без аналога в V1 (информационно, не блокирует). */
    public const CLASS_V2_ONLY = 'v2_only';
    /** Расхождение by-design (V1-величина поглощается opening-балансом; не блокирует). */
    public const CLASS_PLAN_CHANGE = 'plan_change';

    protected $fillable = [
        'run_id', 'member_id', 'check',
        'v1_amount_cents', 'v2_amount_cents', 'delta_cents',
        'classification', 'note',
    ];

    protected $casts = [
        'v1_amount_cents' => 'integer',
        'v2_amount_cents' => 'integer',
        'delta_cents' => 'integer',
    ];
}
