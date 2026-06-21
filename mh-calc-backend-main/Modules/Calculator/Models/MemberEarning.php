<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $member_id
 * @property string $total
 * @property ?array $by_type
 */
class MemberEarning extends Model
{
    public const CREATED_AT = null;

    protected $fillable = [
        'member_id',
        'total',
        'by_type',
        'updated_at',
    ];

    protected $casts = [
        'by_type' => 'array',
        'updated_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
