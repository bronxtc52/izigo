<?php

namespace Modules\Calculator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Calculator\Dto\LoginLocalDto;

class LoginLocalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string',
        ];
    }

    public function getDto(): LoginLocalDto
    {
        $validated = $this->validated();

        return LoginLocalDto::from([
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
    }
}
