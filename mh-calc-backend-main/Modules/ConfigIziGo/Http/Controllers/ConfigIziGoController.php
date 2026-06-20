<?php

namespace Modules\ConfigIziGo\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Foundation\Application;
use Modules\ConfigIziGo\Enums\LocaleEnum;
use Modules\ConfigIziGo\Http\Resources\LocaleCollection;

/**
 * @group ConfigIziGo
 */
class ConfigIziGoController extends Controller
{
    /**
     * Supported locales list
     *
     * Всегда, на все запросы, нужно присылать в заголовках
     * Accept-Language и Accept-Currency
     * Значения из списка допустимых.
     *
     *
     * @response
     * {
     *    "data": {
     *        "kk": {
     *            "code": "kk",
     *            "name": "Казахстан — KZT",
     *            "currency": "KZT",
     *            "country": "Казахстан",
     *            "language": "Казахский"
     *        },
     *        "mn": {
     *            "code": "mn",
     *            "name": "Монголия — MNT",
     *            "currency": "MNT",
     *            "country": "Монголия",
     *            "language": "Монгольский"
     *        },
     *        "ru": {
     *            "code": "ru",
     *            "name": "Россия — RUB",
     *            "currency": "RUB",
     *            "country": "Россия",
     *            "language": "Русский"
     *        },
     *        "uz": {
     *            "code": "uz",
     *            "name": "Узбекистан — UZS",
     *            "currency": "UZS",
     *            "country": "Узбекистан",
     *            "language": "Узбекский"
     *        },
     *        "ky": {
     *            "code": "ky",
     *            "name": "Кыргызстан — KGS",
     *            "currency": "KGS",
     *            "country": "Кыргызстан",
     *            "language": "Кыргызский"
     *        },
     *        "az": {
     *            "code": "az",
     *            "name": "Азербайджан — AZN",
     *            "currency": "AZN",
     *            "country": "Азербайджан",
     *            "language": "Азербайджанский"
     *        }
     *    }
     *}
     *
     * @return Application|\Illuminate\Foundation\Application|mixed
     */
    public function getLocales()
    {
        return resolve(LocaleCollection::class, [
            'resource' => LocaleEnum::getInstances()
        ]);
    }

}
