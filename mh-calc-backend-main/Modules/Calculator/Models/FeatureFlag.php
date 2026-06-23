<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Рантайм фиче-флаг (C3, Block C). key→enabled, deny-by-default. Описание — для админки.
 * updated_by — участник, последним переключивший флаг.
 *
 * @property int $id
 * @property string $key
 * @property bool $enabled
 * @property ?string $description
 * @property ?int $updated_by
 */
class FeatureFlag extends Model
{
    protected $fillable = [
        'key',
        'enabled',
        'description',
        'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'updated_by' => 'integer',
    ];
}
