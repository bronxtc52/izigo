<?php

namespace Modules\ConfigIziGo\Helpers;

use Illuminate\Support\Facades\App;

class Locale
{
    public static function lang(): string
    {
        return App::getLocale();
    }

    public static function currency(): string
    {
        return config('app.currency_code');
    }
}
