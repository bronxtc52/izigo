<?php

namespace Modules\Calculator\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Calculator\Dto\LoginLocalDto;
use Modules\Calculator\Dto\RegisterLocalDto;
use Modules\Calculator\Models\CalculatorUser;
use Modules\Calculator\Models\CalculatorUserToken;

/**
 * Локальная авторизация по email+паролю без SSO (только dev).
 * Выдаёт тот же CalculatorUserToken, что и SSO-флоу.
 */
class LocalAuthService
{
    // Срок жизни токена локального входа — больше SSO для удобства dev.
    const TOKEN_LIFETIME_DAYS = 30;

    public function register(RegisterLocalDto $data): CalculatorUserToken
    {
        $user = CalculatorUser::query()->create([
            'email' => $data->email,
            'password' => Hash::make($data->password),
            'first_name' => $data->first_name,
            'last_name' => $data->last_name,
            'full_name' => trim(($data->first_name ?? '') . ' ' . ($data->last_name ?? '')) ?: null,
            'language' => $data->language,
            'currency' => $data->currency,
        ]);

        return $this->issueToken($user);
    }

    public function login(LoginLocalDto $data): ?CalculatorUserToken
    {
        /** @var CalculatorUser|null $user */
        $user = CalculatorUser::query()->where('email', $data->email)->first();

        if (!$user || !$user->password || !Hash::check($data->password, $user->password)) {
            return null;
        }

        return $this->issueToken($user);
    }

    private function issueToken(CalculatorUser $user): CalculatorUserToken
    {
        $uniqueString = uniqid('', true) . Str::random(40) . microtime(true);

        /** @var CalculatorUserToken $token */
        $token = CalculatorUserToken::query()->create([
            'calculator_user_id' => $user->id,
            'token' => hash('sha256', $uniqueString),
            'expires_at' => Carbon::now()->addDays(self::TOKEN_LIFETIME_DAYS),
        ]);

        // подгружаем владельца для построения профиля в ответе
        $token->setRelation('user', $user);

        return $token;
    }
}
