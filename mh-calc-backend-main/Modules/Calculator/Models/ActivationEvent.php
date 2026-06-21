<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $member_id
 * @property int $package_id
 * @property string $idempotency_key
 * @property string $status
 */
class ActivationEvent extends Model
{
    public const UPDATED_AT = null; // только created_at

    protected $fillable = [
        'member_id',
        'package_id',
        'idempotency_key',
        'status',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }
}
