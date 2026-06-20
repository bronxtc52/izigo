<?php

namespace Modules\Calculator\Rules;

class NodeRuleSet
{
    public function rules(): array
    {
        return [
            'username' => [
                'string',
                'between:1,36',
                'regex:/^[\s\p{L}\p{N}@._-]+$/u',
            ],

            'package_id' => ['integer', 'exists' => 'exists:calculator_packages,id'],
        ];
    }
}
