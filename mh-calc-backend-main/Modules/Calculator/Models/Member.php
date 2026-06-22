<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * Реальный участник сети. placement (parent/position/path) — бинар; sponsor — ЛП.
 * Идентичность платформы — Telegram (telegram_id). RBAC-роли — на участнике.
 *
 * @property int $id
 * @property int $telegram_id
 * @property ?string $telegram_username
 * @property ?string $language
 * @property ?int $sponsor_id
 * @property ?int $parent_id
 * @property ?string $position  left|right
 * @property ?int $package_id
 * @property ?int $rank_id
 * @property ?string $name
 * @property string $ref_code
 * @property string $status  registered|active
 * @property int $version
 * @property ?string $path
 */
class Member extends Model
{
    /** Веб-админка: Sanctum personal access tokens (вход через Telegram Login Widget). */
    use HasApiTokens;

    protected $fillable = [
        'sponsor_id',
        'parent_id',
        'position',
        'package_id',
        'rank_id',
        'name',
        'ref_code',
        'telegram_id',
        'telegram_username',
        'language',
        'status',
        'version',
        'path',
    ];

    /** RBAC-роли участника (owner|finance|leader|support). */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'member_roles', 'member_id', 'role_id')
            ->withPivot('leader_scope_member_id');
    }

    public function hasAnyRole(array $names): bool
    {
        return $this->roles()->whereIn('name', $names)->exists();
    }

    public function isOwner(): bool
    {
        return $this->hasAnyRole(['owner']);
    }

    /** Член-«охват» лидера (его поддерево). null, если не лидер/без охвата. */
    public function leaderScopeMemberId(): ?int
    {
        $leader = $this->roles()->where('name', 'leader')->first();
        return $leader?->pivot?->leader_scope_member_id;
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sponsor_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
