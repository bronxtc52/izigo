<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ЛЕГАСИ: аккаунт публичного калькулятора-витрины (анонимный инструмент сохранения
 * структур, идентичность по out_id/токену). НЕ участвует в авторизации платформы —
 * та работает только через Telegram (Member). Оставлен ради персистентности витрины.
 *
 * @property int $id
 * @property string $email
 * @property string $avatar
 * @property ?string $last_name
 * @property ?string $first_name
 * @property ?string $patronymic
 * @property ?string $full_name
 * @property ?string $language
 * @property ?string $currency
 */
class CalculatorUser extends Model
{
    protected $fillable = [
        'email',
        'avatar',
        'last_name',
        'first_name',
        'patronymic',
        'full_name',
        'language',
        'currency',
    ];

    /**
     * Профиль для ответа авторизации (id, контакты, локаль).
     */
    public function profileArray(): array
    {
        return [
            'outId' => (string) $this->id,
            'avatar' => $this->avatar,
            'email' => $this->email,
            'lastName' => $this->last_name,
            'firstName' => $this->first_name,
            'patronymic' => $this->patronymic,
            'fullName' => $this->full_name,
            'language' => $this->language ?? '',
            'currency' => $this->currency ?? '',
        ];
    }
}
