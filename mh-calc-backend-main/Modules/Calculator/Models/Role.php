<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Роль RBAC (owner|finance|leader|support). Логика гейтов — S3.
 *
 * @property int $id
 * @property string $name
 * @property ?string $label
 */
class Role extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'label'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            Member::class,
            'member_roles',
            'role_id',
            'member_id'
        )->withPivot('leader_scope_member_id');
    }
}
