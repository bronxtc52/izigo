<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись аудит-лога админ-действия. Append-only (только created_at).
 *
 * @property int $id
 * @property ?int $actor_member_id
 * @property string $action
 * @property string $entity_type
 * @property ?int $entity_id
 * @property ?array $before
 * @property ?array $after
 */
class AdminAuditLog extends Model
{
    protected $table = 'admin_audit_log';

    public $timestamps = false;

    protected $fillable = [
        'actor_member_id',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'created_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'actor_member_id');
    }
}
