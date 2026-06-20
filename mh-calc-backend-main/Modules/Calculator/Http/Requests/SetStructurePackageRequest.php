<?php

namespace Modules\Calculator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Calculator\Dto\SetStructurePackageData;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Models\Structure\Structure;
use Modules\Calculator\Rules\NodeRuleSet;

/**
 * @bodyParam structure_token string required
 * @bodyParam top_node_id int required
 * @bodyParam package_id int required
 */
class SetStructurePackageRequest extends FormRequest
{
    public ?Structure $structure = null;

    public function bodyParameters()
    {
        return [
            'structure_token' => [
                'description' => 'Токен структуры с доступом на редактирование.',
                'required' => true,
                'example' => 'sdfsdgddgfdg23434grthg56h',
            ],
            'top_node_id' => [
                'description' => 'ID верхнего узла структуры.',
                'required' => false,
                'example' => 1,
            ],
            'package_id' => [
                'description' => 'ID контракта, связанного с узлом.',
                'required' => true,
                'example' => 1
            ],
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        $this->structure = Structure::findByToken($this->input('structure_token'));
        if (!$this->structure || !$this->structure->canEdit(CalculatorAuth::token())) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        if (!$this->structure) {
            return [];
        }

        $nodeRules = resolve(NodeRuleSet::class)->rules();

        return [
            'top_node_id' => ['sometimes', 'integer',
                function ($attribute, $value, $fail) {
                    if (!$this->structure->getNodeById($this->structure->getRoot(), $value)) {
                        $fail(__('calculator::structure.validate.top_node_not_exists'));
                    }
                }, 'bail'],
            'package_id' => array_merge(['required'], $nodeRules['package_id'])
        ];
    }

    public function attributes(): array
    {
        return [
            'structure_token' => __('calculator::structure.add_node.structure_token'),
            'top_node_id' => __('calculator::structure.add_node.top_node_id'),
            'package_id' => __('calculator::structure.add_node.package_id'),
        ];
    }

    public function getDto(): SetStructurePackageData
    {
        $validated = $this->validated();

        return SetStructurePackageData::from([
            'structure' => $this->structure,
            'top_node_id' => $validated['top_node_id'] ?? null,
            'package_id' => $validated['package_id']
        ]);
    }
}
