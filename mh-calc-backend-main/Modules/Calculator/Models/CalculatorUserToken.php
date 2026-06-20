<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $calculator_user_id
 * @property string $token
 * @property Carbon $expires_at
 *
 * @property-read CalculatorUser $user
 *
 */
class CalculatorUserToken extends Model
{
    protected $fillable = [
        'calculator_user_id',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime'
    ];

    public function user():HasOne
    {
        return $this->hasOne(CalculatorUser::class, 'id', 'calculator_user_id');
    }

    public function isValid()
    {
        return $this->expires_at->isFuture();
    }
}
