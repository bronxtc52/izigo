<?php

namespace Modules\Calculator\Models;


use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

/**
 * @property int $sort
 * @property string $alias
 *
 * @property-read string $name
 * @property-read string $description
 *
 * @property-read PackageVolume $volume
 */
class Package extends Model
{
    protected $table = 'calculator_packages';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [];
    private static ?array $map = null;

    public static function getMap(string $locale): Collection
    {
        if (self::$map == null) {
            self::$map = [];
        }

        if (empty(self::$map[$locale])) {
            self::$map[$locale] = self::query()->orderBy('sort')
                ->with([
                    'volume' => function ($query) use ($locale) {
                        $query->where('locale', $locale);
                    }])
                ->get()
                ->keyBy('id');
        }

        return self::$map[$locale];
    }

    public static function getName(?int $package_id, string $locale)
    {
        $map = self::getMap($locale);
        return $map[$package_id]->name ?? null;
    }

    public static function getById(?int $packageId, string $locale): ?self
    {
        if (!$packageId) return null;
        $map = self::getMap($locale);
        return $map[$packageId] ?? null;
    }

    public function getNameAttribute(): string
    {
        return __("calculator::package.name.sort_{$this->sort}");
    }

    public function getDescriptionAttribute(): string
    {
        return __("calculator::package.description.sort_{$this->sort}", [
            'name' => $this->name,
            'pv' => CurrencyFormatter::pv($this->volume->pv),
            'bv' => CurrencyFormatter::bv($this->volume->bv)
        ]);
    }

    public function volume(): HasOne
    {
        return $this->hasOne(PackageVolume::class, 'calculator_package_id');
    }


}
