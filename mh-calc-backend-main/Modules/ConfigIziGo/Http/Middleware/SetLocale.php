<?php

namespace Modules\ConfigIziGo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\ConfigIziGo\Enums\LocaleEnum;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        config(['translatable.locales' => LocaleEnum::getValues()]);
        $locale = LocaleEnum::getCorrect($request->server->get('HTTP_ACCEPT_LANGUAGE'));

        if (null !== $locale && in_array($locale, config('translatable.locales'))) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
