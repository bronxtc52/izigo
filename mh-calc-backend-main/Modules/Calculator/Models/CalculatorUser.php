<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $email
 * @property ?string $password
 * @property string $avatar
 * @property ?string $last_name
 * @property ?string $first_name
 * @property ?string $patronymic
 * @property ?string $full_name
 * @property ?string $language
 * @property ?string $currency
 */
class CalculatorUser extends Model
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'calculator_user_id', 'role_id')
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

    protected $fillable = [
        'email',
        'password',
        'avatar',
        'last_name',
        'first_name',
        'patronymic',
        'full_name',
        'language',
        'currency',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * Профиль для ответа авторизации (id, контакты, локаль).
     */
    public function profileArray(): array
    {
        return [
            'outId' => (string) $this->id,
            'avatar' => $this->avatar,
            'email' => $this->email,
            'lastName' => $this->last_name,
            'firstName' => $this->first_name,
            'patronymic' => $this->patronymic,
            'fullName' => $this->full_name,
            'language' => $this->language ?? '',
            'currency' => $this->currency ?? '',
        ];
    }
}
