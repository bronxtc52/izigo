<?php

namespace Modules\Calculator\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Calculator\Http\Resources\PackageCollection;
use Modules\Calculator\Models\Package;
use Modules\ConfigIziGo\Enums\LocaleEnum;
use Modules\ConfigIziGo\Helpers\Locale;


/**
 * @group Calculator
 */
class PackageController extends Controller
{
    /**
     * Packages list
     *
     * @return PackageCollection
     *
     * @response
     * {
     *      "data": [
     *          {
     *              "id": 1,
     *              "sort": 1,
     *              "name": "Start",
     *              "pv": "100",
     *              "pv_formatted": "100.00 PV",
     *              "bv": "6750",
     *              "bv_formatted": "6 750.00 BV"
     *          },
     *          {
     *              "id": 2,
     *              "sort": 2,
     *              "name": "Business",
     *              "pv": "200",
     *              "pv_formatted": "200.00 PV",
     *              "bv": "13500",
     *              "bv_formatted": "13 500.00 BV"
     *          },
     *          {
     *              "id": 3,
     *              "sort": 3,
     *              "name": "Elite",
     *              "pv": "600",
     *              "pv_formatted": "600.00 PV",
     *              "bv": "40500",
     *              "bv_formatted": "40 500.00 BV"
     *          }
     *      ]
     *  }
     */
    public function index()
    {
        $currency = LocaleEnum::create(Locale::currency());
        $packages = Package::with([
            'volume' => function ($query) use ($currency) {
                $query->where('locale', $currency->value);
            }])
            ->orderBy('sort')
            ->get();
        return resolve(PackageCollection::class, ['resource' => $packages]);
    }

}
