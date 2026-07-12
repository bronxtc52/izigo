<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T05: денормализованная проекция статуса участника «сейчас» (v2_partner_states).
 * Grace-канон MF-7: state='client' + grace_expires_at; терминальный — grace_expired.
 * Исторические as-of чтения — через RankHistory/TierHistory, не отсюда.
 *
 * @property int $member_id
 * @property string $state none|client|consultant|grace_expired
 * @property ?string $current_rank_code
 * @property ?string $current_tier
 * @property string $personal_pv_total decimal(18,6)
 * @property ?\Illuminate\Support\Carbon $client_achieved_at
 * @property ?\Illuminate\Support\Carbon $grace_started_at
 * @property ?\Illuminate\Support\Carbon $grace_expires_at
 * @property ?string $grace_outcome consultant|annulled
 * @property ?\Illuminate\Support\Carbon $grace_annulled_at
 */
class PartnerState extends Model
{
    public const STATE_NONE = 'none';
    public const STATE_CLIENT = 'client';
    public const STATE_CONSULTANT = 'consultant';
    public const STATE_GRACE_EXPIRED = 'grace_expired';

    public const OUTCOME_CONSULTANT = 'consultant';
    public const OUTCOME_ANNULLED = 'annulled';

    protected $table = 'v2_partner_states';

    protected $primaryKey = 'member_id';

    public $incrementing = false;

    protected $fillable = [
        'member_id',
        'state',
        'current_rank_code',
        'current_tier',
        'personal_pv_total',
        'client_achieved_at',
        'grace_started_at',
        'grace_expires_at',
        'grace_outcome',
        'grace_annulled_at',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'personal_pv_total' => 'string',
        'client_achieved_at' => 'datetime',
        'grace_started_at' => 'datetime',
        'grace_expires_at' => 'datetime',
        'grace_annulled_at' => 'datetime',
    ];
}
