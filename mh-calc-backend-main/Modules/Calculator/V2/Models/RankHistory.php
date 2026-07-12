<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T05: монотонная история достигнутых рангов (v2_rank_history) — «ранг навсегда»
 * (DEC-020). unique(member_id, rank_code) = идемпотентный триггер наград T10;
 * при скачке — строка на каждый пройденный ранг с одним evaluation_id (DEC-040).
 *
 * @property int $id
 * @property int $member_id
 * @property string $rank_code
 * @property int $rank_ordinal
 * @property ?string $evaluation_id uuid
 * @property int $policy_version_id
 */
class RankHistory extends Model
{
    protected $table = 'v2_rank_history';

    public $timestamps = false; // только created_at при insert; история immutable

    protected $fillable = [
        'member_id',
        'rank_code',
        'rank_ordinal',
        'achieved_at',
        'evaluation_id',
        'policy_version_id',
        'created_at',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'rank_ordinal' => 'integer',
        'achieved_at' => 'datetime',
        'policy_version_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
