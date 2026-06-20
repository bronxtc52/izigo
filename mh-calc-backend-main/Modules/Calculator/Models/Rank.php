<?php

namespace Modules\Calculator\Models;


use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $sort
 * @property string $alias
 *
 * @property float $binary_small_branch_volume Объем малой ветки бинара
 * @property integer $personal_count personal_count лично приглашенных
 * @property integer $personal_in_rank_count personal_in_rank_count лично приглашенных в ранге personal_in_rank_id
 * @property integer $personal_in_rank_id personal_in_rank_count лично приглашенных в ранге personal_in_rank_id
 *
 * @property-read RankBonus $bonus
 */
class Rank extends Model
{
    protected $table = 'calculator_ranks';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [];

    private static ?array $map = null;

    public function bonus(): HasOne
    {
        return $this->hasOne(RankBonus::class, 'calculator_rank_id');
    }

    public static function getMap(string $locale): Collection
    {
        if (self::$map == null) {
            self::$map = [];
        }

        if (empty(self::$map[$locale])) {
            self::$map[$locale] = self::query()
                ->with([
                    'bonus' => function ($query) use ($locale) {
                        $query->where('locale', $locale);
                    }])
                ->orderBy('sort')
                ->get()
                ->keyBy('id');
        }
        return self::$map[$locale];
    }

    public static function getById(int $rankId, string $locale): ?self
    {
        $map = self::getMap($locale);
        return $map[$rankId] ?? null;
    }

    public static function getName(?int $rank_id, string $locale)
    {
        $map = self::getMap($locale);
        return $map[$rank_id]->name ?? null;
    }

    public function getNameAttribute(): string
    {
        return __("calculator::rank.name.{$this->alias}");
    }


}
