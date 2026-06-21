<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Реальный участник сети. placement (parent/position/path) — бинар; sponsor — ЛП.
 *
 * @property int $id
 * @property ?int $calculator_user_id
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
    protected $fillable = [
        'calculator_user_id',
        'sponsor_id',
        'parent_id',
        'position',
        'package_id',
        'rank_id',
        'name',
        'ref_code',
        'telegram_id',
        'telegram_username',
        'status',
        'version',
        'path',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(CalculatorUser::class, 'calculator_user_id');
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
