<?php

namespace Modules\Calculator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Calculator\Dto\UserNodeData;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Models\Package;
use Modules\Calculator\Models\Structure\Structure;
use Modules\Calculator\Rules\NodeRuleSet;

/**
 * @bodyParam username string
 * @bodyParam package_id int
 */
class StructureCreateRequest extends FormRequest
{
    public function bodyParameters()
    {
        return [
            'username' => [
                'description' => 'Имя пользователя корневого узла.',
                'required' => true,
                'example' => 'user123',
            ],
            'package_id' => [
                'description' => 'ID контракта, связанного с корневым узлом.',
                'required' => false,
                'example' => 4,
            ],
        ];
    }

    public function messages()
    {
        return [
            'username.regex' => __('calculator::structure.validate.username_regex')
        ];
    }

    public function rules(): array
    {
        $nodeRules = resolve(NodeRuleSet::class)->rules();

        return [
            'username' => array_merge(['sometimes'], $nodeRules['username']),
            'package_id' => ['sometimes', 'int', 'exists:calculator_packages,id']
        ];
    }

    public function getDto(): UserNodeData
    {
        $validated = $this->validated();

        return UserNodeData::from([
            'username' => $validated['username'] ?? null,
            'package_id' => $validated['package_id'] ?? Package::orderBy('sort', 'desc')->value('id')
        ]);
    }
}
