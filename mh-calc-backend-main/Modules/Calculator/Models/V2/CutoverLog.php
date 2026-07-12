<?php

namespace Modules\Calculator\Models\V2;

use Illuminate\Database\Eloquent\Model;

/**
 * mh-full-plan T15: append-only аудит-строка действия cutover (v2_cutover_log).
 * Пишется командами calc-v2:cutover-migrate / calc-v2:parity-check; никогда не апдейтится.
 */
class CutoverLog extends Model
{
    protected $table = 'v2_cutover_log';

    public const UPDATED_AT = null;
    public const CREATED_AT = 'created_at';

    public const ACTION_BRONZE_TARIFF = 'bronze_tariff';
    public const ACTION_OPENING = 'opening_migration';
    public const ACTION_RECONCILIATION = 'reconciliation';
    public const ACTION_PARITY = 'parity';
    public const ACTION_PHASE = 'phase';

    public const PHASE_PRE = 'pre';
    public const PHASE_DRY_RUN = 'dry_run';
    public const PHASE_MIGRATED = 'migrated';
    public const PHASE_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'action', 'phase', 'actor', 'dry_run',
        'member_id', 'amount_cents', 'tx_id', 'detail', 'created_at',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'amount_cents' => 'integer',
        'detail' => 'array',
        'created_at' => 'datetime',
    ];
}
