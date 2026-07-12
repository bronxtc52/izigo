<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T10: единоразовая квалификационная награда (v2_award_entitlements). Источник
 * истины «мы должны участнику X USD за достигнутый статус»; деньги проведены на
 * БС кредит-лотом T02 (source_type='award', без сгорания — MF-9). Выплата вручную
 * (markPaid, owner-only). unique(member_id, award_code, stage_no) — идемпотентный
 * триггер (DEC-040/DEC-042). Награды НЕ отзываются при возвратах (DEC-027/DEC-020).
 *
 * @property int         $id
 * @property int         $member_id
 * @property string      $award_code
 * @property int         $stage_no
 * @property int         $amount_cents
 * @property ?int        $policy_version_id
 * @property string      $trigger_type
 * @property ?string     $trigger_ref
 * @property string      $status
 * @property ?int        $paid_by_admin_id
 * @property ?string     $note
 * @property ?array      $meta
 */
class AwardEntitlement extends Model
{
    protected $table = 'v2_award_entitlements';

    // Награда начислена на БС, ждёт ручной выплаты.
    public const STATUS_GRANTED = 'granted';
    // Админ приостановил выплату (markPaid заблокирован до release).
    public const STATUS_ON_HOLD = 'on_hold';
    // Выплачено вручную (проводка БС → company_payouts_paid).
    public const STATUS_PAID_OUT = 'paid_out';
    // Админ решил не выплачивать (статус-only, начисление НЕ удаляется — DEC-041/043).
    public const STATUS_FORFEITED = 'forfeited';

    // Триггеры гранта.
    public const TRIGGER_RANK_ACHIEVED = 'rank_achieved';
    public const TRIGGER_GLOBAL_QUALIFICATION = 'global_qualification';

    /** Код награды VP (три этапа: 1 — достижение ранга, 2/3 — квалификации глобального). */
    public const CODE_VICE_PRESIDENT = 'VICE_PRESIDENT';

    protected $fillable = [
        'member_id', 'award_code', 'stage_no', 'amount_cents', 'policy_version_id',
        'trigger_type', 'trigger_ref', 'status', 'granted_at', 'posted_at',
        'paid_at', 'paid_by_admin_id', 'note', 'meta',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'stage_no' => 'integer',
        'amount_cents' => 'integer',
        'policy_version_id' => 'integer',
        'paid_by_admin_id' => 'integer',
        'meta' => 'array',
        'granted_at' => 'datetime',
        'posted_at' => 'datetime',
        'paid_at' => 'datetime',
    ];
}
