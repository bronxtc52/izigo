<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Лид — потенциальный участник, перешедший по реф-ссылке, но ещё не купивший пакет.
 * ВНЕ бинар-дерева (не занимает слот), без ref_code (не рекрутирует). Закреплён за
 * спонсором (sponsor_id → будущий личный реферал) до expires_at. Спонсора можно
 * менять, пока лид не оплатил; первая подтверждённая оплата промоутит лида в Member
 * (запись удаляется) и фиксирует спонсора навсегда.
 *
 * @property int $id
 * @property int $telegram_id
 * @property ?string $telegram_username
 * @property ?string $language
 * @property ?string $name
 * @property ?int $sponsor_id
 * @property \Illuminate\Support\Carbon $expires_at
 */
class Lead extends Model
{
    protected $fillable = [
        'telegram_id',
        'telegram_username',
        'language',
        'name',
        'sponsor_id',
        'expires_at',
    ];

    protected $casts = [
        'telegram_id' => 'integer',
        'sponsor_id' => 'integer',
        'expires_at' => 'datetime',
    ];

    /** Спонсор-замок (Member). Личный реферал «закрепится» при первой покупке. */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'sponsor_id');
    }

    /** Лид-окно истекло — привязка к спонсору больше не действует. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
