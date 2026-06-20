<?php

namespace Modules\Calculator\Dto;

use Spatie\LaravelData\Data;

class RegisterLocalDto extends Data
{
    public function __construct(
        public string $email,
        public string $password,
        public ?string $first_name = null,
        public ?string $last_name = null,
        public ?string $language = null,
        public ?string $currency = null,
    ) {
    }
}
