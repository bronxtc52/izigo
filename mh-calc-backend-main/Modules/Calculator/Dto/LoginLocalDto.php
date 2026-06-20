<?php

namespace Modules\Calculator\Dto;

use Spatie\LaravelData\Data;

class LoginLocalDto extends Data
{
    public function __construct(
        public string $email,
        public string $password,
    ) {
    }
}
