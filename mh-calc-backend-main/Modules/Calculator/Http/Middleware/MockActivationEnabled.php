<?php

namespace Modules\Calculator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Гейт мок-активации пакета БЕЗ оплаты (POST /cabinet/activate-package). С Фазы 3 activate()
 * пишет реальные выводимые бонусы в ledger аплайну — бесплатная активация в проде = «печать
 * денег из воздуха» (аудит docs/reviews/2026-07-02-production-review.md, блокер B-1).
 *
 * Deny-by-default: без config('calculator.allow_mock_activation') роут отвечает 404, как будто
 * его нет в API. Флаг включается ТОЛЬКО в тест-окружении (phpunit.xml), где эндпоинт нужен как
 * фикстура «сделать участника оплаченным». Боевой путь активации — оплаченный заказ (OrderService).
 * Config читается в рантайме (per-request), поэтому гейт тестируем без пересборки route-кэша.
 */
class MockActivationEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! (bool) config('calculator.allow_mock_activation', false)) {
            throw new NotFoundHttpException();
        }

        return $next($request);
    }
}
