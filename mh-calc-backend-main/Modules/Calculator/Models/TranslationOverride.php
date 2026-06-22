<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * i18n-оверрайд (C4, Block C): одна переопределённая строка перевода (locale,key)→value.
 * Sparse — в БД лежат только переопределённые ключи; дефолты в статических locale-JSON.
 * updated_by — участник, последним правивший строку.
 *
 * @property int $id
 * @property string $locale
 * @property string $key
 * @property string $value
 * @property ?int $updated_by
 */
class TranslationOverride extends Model
{
    protected $fillable = [
        'locale',
        'key',
        'value',
        'updated_by',
    ];

    protected $casts = [
        'updated_by' => 'integer',
    ];
}
