<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\AiAssistantService;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;

/**
 * AI-ассистент партнёра: один эндпоинт POST /cabinet/assistant/ask.
 *
 * Гейты: telegram.auth (middleware), feature flag ai_assistant (backend),
 * rate limit 10 req/min по member_id.
 */
class AiAssistantController
{
    public function __construct(
        private readonly AiAssistantService $assistant,
        private readonly FeatureFlagService $flags,
    ) {
    }

    public function ask(Request $request): JsonResponse
    {
        // Feature flag — проверяем и на backend (не только фронт).
        if (! $this->flags->isEnabled('ai_assistant')) {
            return response()->json(['message' => 'Feature is not available.', 'code' => 'FEATURE_DISABLED'], 403);
        }

        $member = $this->member($request);

        // Лид (не купил пакет) — member null или status != active.
        if ($member === null || ! $member->isActive()) {
            return response()->json(['message' => 'Available after package activation.', 'code' => 'FEATURE_DISABLED'], 403);
        }

        // Валидация до инкремента счётчика — невалидный запрос не тратит лимит.
        $data = $request->validate([
            'question' => 'required|string|max:500',
            'locale' => 'required|in:ru,en',
        ]);

        // Rate limit строго по member_id.
        $limitKey = 'ai-assistant:' . $member->id;
        $maxPerMinute = config('calculator.assistant_rate_per_minute', 10);

        if (RateLimiter::tooManyAttempts($limitKey, $maxPerMinute)) {
            $seconds = RateLimiter::availableIn($limitKey);

            return response()->json([
                'message' => 'Too many requests. Please wait.',
                'code' => 'RATE_LIMITED',
                'retry_after' => $seconds,
            ], 429);
        }

        RateLimiter::hit($limitKey, 60);

        $rankAlias = $member->rank?->alias;
        $packageName = $member->package?->name;

        $result = $this->assistant->ask(
            $data['question'],
            $data['locale'],
            $rankAlias,
            $packageName,
        );

        if ($result['error'] !== null) {
            return response()->json([
                'message' => 'AI assistant is temporarily unavailable. Please try again later.',
                'code' => $result['error'],
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'answer' => $result['answer'],
            'locale' => $data['locale'],
        ]);
    }

    private function member(Request $request): ?Member
    {
        return $request->attributes->get('member');
    }
}
