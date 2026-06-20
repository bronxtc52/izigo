<?php

namespace Modules\Calculator\Http\Controllers;

use Modules\Calculator\Http\Requests\LoginLocalRequest;
use Modules\Calculator\Http\Requests\RegisterLocalRequest;
use Modules\Calculator\Models\CalculatorUser;
use Modules\Calculator\Services\LocalAuthService;

/**
 * Локальная авторизация по email+паролю — единственный способ входа.
 * Ответ: { status, token, email, profile }.
 *
 * @group Calculator
 */
class LocalAuthController
{
    public function __construct(private readonly LocalAuthService $service)
    {
    }

    public function register(RegisterLocalRequest $request): \Illuminate\Http\JsonResponse
    {
        $token = $this->service->register($request->getDto());

        return $this->tokenResponse($token);
    }

    public function login(LoginLocalRequest $request): \Illuminate\Http\JsonResponse
    {
        $token = $this->service->login($request->getDto());

        if ($token === null) {
            return response()->json([
                'status' => 'error',
                'message' => __('calculator::auth.error.Failure'),
            ], 401);
        }

        return $this->tokenResponse($token);
    }

    private function tokenResponse($token): \Illuminate\Http\JsonResponse
    {
        /** @var CalculatorUser $user */
        $user = $token->user;

        return response()->json([
            'status' => 'success',
            'token' => $token->token,
            'email' => $user->email,
            'profile' => $user->profileArray(),
        ]);
    }
}
