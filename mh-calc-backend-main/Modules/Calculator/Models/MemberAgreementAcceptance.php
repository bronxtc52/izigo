<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Факт акцепта версии пользовательского соглашения участником (B3).
 *
 * @property int $id
 * @property int $member_id
 * @property int $version
 * @property \Illuminate\Support\Carbon $accepted_at
 */
class MemberAgreementAcceptance extends Model
{
    protected $fillable = ['member_id', 'version', 'accepted_at'];

    protected $casts = [
        'version' => 'integer',
        'accepted_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
