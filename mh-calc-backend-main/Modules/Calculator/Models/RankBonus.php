<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $sort
 * @property int $calculator_rank_id
 * @property string $locale
 * @property float $rank_bonus_amount
 */
class RankBonus extends Model
{
    protected $table = 'calculator_rank_bonuses';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [];

}
