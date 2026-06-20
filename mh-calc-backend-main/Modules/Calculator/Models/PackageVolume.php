<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $sort
 * @property int $calculator_package_id
 * @property string $locale
 * @property float $pv
 * @property float $bv
 */
class PackageVolume extends Model
{
    protected $table = 'calculator_package_volumes';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [];

}
