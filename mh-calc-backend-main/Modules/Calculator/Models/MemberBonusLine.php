<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $recipient_member_id
 * @property string $type
 * @property string $amount
 * @property ?array $basis
 * @property ?int $source_event_id
 */
class MemberBonusLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'recipient_member_id',
        'type',
        'amount',
        'basis',
        'source_event_id',
        'calculated_at',
    ];

    protected $casts = [
        'basis' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'recipient_member_id');
    }
}
