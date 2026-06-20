<?php

namespace Modules\ConfigIziGo\Enums;

use BenSampo\Enum\Enum;
use Modules\ConfigIziGo\Helpers\Locale;

class LocaleEnum extends Enum
{

    const kk = 'kk';
    const mn = 'mn';
    const ru = 'ru';
    const uz = 'uz';
    const ky = 'ky';
    const az = 'az';

    public string $name;

    public string $language;
    public string $currency;
    public string $country;

    public function __construct($value)
    {
        parent::__construct($value);
        $this->name = __("configizigo::locales.name.$value");
        $this->currency = __("configizigo::locales.currency.$value");
        $this->country = __("configizigo::locales.country.$value");
        $this->language = __("configizigo::locales.language.$value");
    }

    private static $map = [];

    public static function create(string $locale): self
    {
        if (empty(self::$map[$locale])) {
            self::$map[$locale] = new self($locale);
        }
        return self::$map[$locale];
    }

    public static function getCorrectByCurrencyAbbr(?string $value):string
    {
        foreach (self::getValues() as $code)
        {
            $locale = LocaleEnum::create($code);
            if ($value == $locale->currency)
            {
                return $code;
            }
        }
        return config('app.currency_code');
    }

    public static function getCorrect(?string $value)
    {
        if ($value && in_array($value, self::getValues())) {
            return $value;
        }
        return config('app.currency_code');
    }
}
