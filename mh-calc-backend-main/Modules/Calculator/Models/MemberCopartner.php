<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * C6 (Block C): со-партнёр / наследник участника — справочная запись в профиле.
 * НЕ влияет на расчёт бонусов, дерево и авторизацию. Партнёр ведёт несколько
 * записей (cabinet); админка только просматривает. share_percent — справочно,
 * сумма долей НЕ валидируется (Gate-A п.15).
 *
 * @property int $id
 * @property int $member_id
 * @property string $kind  copartner|heir
 * @property string $full_name
 * @property ?string $phone
 * @property ?string $share_percent
 * @property ?string $note
 */
class MemberCopartner extends Model
{
    protected $table = 'member_copartners';

    protected $fillable = [
        'member_id',
        'kind',
        'full_name',
        'phone',
        'share_percent',
        'note',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
