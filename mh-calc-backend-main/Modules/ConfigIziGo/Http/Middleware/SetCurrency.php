<?php

namespace Modules\ConfigIziGo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\ConfigIziGo\Enums\LocaleEnum;

class SetCurrency
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
        $currency = LocaleEnum::getCorrect($request->server->get('HTTP_ACCEPT_CURRENCY'));

        config(['app.currency_code' => $currency]);

        return $next($request);
    }
}
