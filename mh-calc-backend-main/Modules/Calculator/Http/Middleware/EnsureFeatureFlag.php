<?php

namespace Modules\Calculator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;

/**
 * Enforcement фиче-флагов Блока C на бэкенде: `feature.flag:{alias}` на группе роутов.
 * Флаг выключен (или не существует — deny-by-default) → 403 FEATURE_DISABLED, тем же
 * контрактом, что inline-проверка AiAssistantController. Скрытие табов на фронте — UX,
 * а не гейт: без этого middleware прямой запрос к API обходил бы «фичи OFF».
 */
class EnsureFeatureFlag
{
    public function __construct(private readonly FeatureFlagService $flags)
    {
    }

    public function handle(Request $request, Closure $next, string $alias): mixed
    {
        if (! $this->flags->isEnabled($alias)) {
            return response()->json([
                'message' => 'Feature is not available.',
                'code' => 'FEATURE_DISABLED',
            ], 403);
        }

        return $next($request);
    }
}
