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

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            CalculatorUser::class,
            'role_user',
            'role_id',
            'calculator_user_id'
        )->withPivot('leader_scope_member_id');
    }
}
