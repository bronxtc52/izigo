<?php

namespace Modules\Calculator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Calculator\Dto\RegisterLocalDto;

class RegisterLocalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|max:255|unique:calculator_users,email',
            'password' => 'required|string|min:6|confirmed',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'language' => 'nullable|string|max:5',
            'currency' => 'nullable|string|max:5',
        ];
    }

    public function getDto(): RegisterLocalDto
    {
        $validated = $this->validated();

        return RegisterLocalDto::from([
            'email' => $validated['email'],
            'password' => $validated['password'],
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'language' => $validated['language'] ?? null,
            'currency' => $validated['currency'] ?? null,
        ]);
    }
}
