<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\CalculatorUserToken;

/**
 * ЛЕГАСИ: хранит текущий токен пользователя калькулятора-витрины (за фасадом
 * CalculatorAuth). Используется токен-флоу витрины (Set/CheckUserTokenMiddleware,
 * /calculator/structure/*). Авторизация ПЛАТФОРМЫ идёт только через Telegram
 * (ResolveTelegramMember), этот сервис к ней не относится.
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
}
