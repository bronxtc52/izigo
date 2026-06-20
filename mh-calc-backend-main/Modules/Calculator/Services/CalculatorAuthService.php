<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\CalculatorUserToken;

/**
 * Хранит текущий токен пользователя калькулятора (за фасадом CalculatorAuth).
 * Используется middleware валидации токена и контроллерами/реквестами.
 * Выдача токенов — в LocalAuthService (локальный вход email+пароль).
 */
class CalculatorAuthService
{
    private ?CalculatorUserToken $currentToken = null;

    public function setToken(?CalculatorUserToken $token): void
    {
        $this->currentToken = $token;
    }

    public function token(): ?CalculatorUserToken
    {
        return $this->currentToken;
    }

    public function check(): bool
    {
        return $this->currentToken !== null && $this->currentToken->isValid();
    }

    public function logout(): void
    {
        if ($this->currentToken)
        {
            CalculatorUserToken::query()->where('id', $this->currentToken->id)->delete();
        }
        $this->currentToken = null;
    }
}
