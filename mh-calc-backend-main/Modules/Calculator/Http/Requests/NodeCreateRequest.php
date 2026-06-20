<?php

namespace Modules\Calculator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Calculator\Dto\NodeCreateData;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Models\Package;
use Modules\Calculator\Models\Structure\Structure;
use Modules\Calculator\Rules\CanBeSponsorRule;
use Modules\Calculator\Rules\NodeRuleSet;
use Modules\Calculator\Rules\SponsorPositionRule;

/**
 * @bodyParam structure_token string required
 * @bodyParam top_node_id int required
 * @bodyParam position int required
 * @bodyParam sponsor_id int required
 * @bodyParam username string
 * @bodyParam package_id int required
 */
class NodeCreateRequest extends FormRequest
{
    public ?Structure $structure = null;

    public function bodyParameters()
    {
        return [
            'top_node_id' => [
                'description' => 'ID верхнего узла структуры.',
                'required' => true,
                'example' => 1,
            ],
            'position' => [
                'description' => 'Позиция узла в структуре.',
                'required' => true,
                'example' => 2,
            ],
            'sponsor_id' => [
                'description' => 'ID спонсора узла.',
                'required' => true,
                'example' => 3,
            ],
            'username' => [
                'description' => 'Имя пользователя узла.',
                'required' => true,
                'example' => 'user123',
            ],
            'package_id' => [
                'description' => 'ID контракта, связанного с узлом.',
                'required' => true,
                'example' => 4,
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
            'top_node_id' => ['required', 'integer',
                function ($attribute, $value, $fail) {
                    if (!$this->structure->getNodeById($this->structure->getRoot(), $value)) {
                        $fail(__('calculator::structure.validate.top_node_not_exists'));
                    }
                }, 'bail'],
            'position' => ['required', 'integer', 'min:1', 'max:' . Structure::WIDTH, 'bail', resolve(SponsorPositionRule::class, [
                'structure' => $this->structure,
                'top_node_id' => $this->input('top_node_id'),
            ])],
            'sponsor_id' => ['sometimes', 'integer', 'bail', resolve(CanBeSponsorRule::class, [
                'structure' => $this->structure,
                'top_node_id' => $this->input('top_node_id'),
            ])],
            'username' => array_merge(['sometimes', 'string', 'bail'],
                $nodeRules['username'],
                [function ($attribute, $value, $fail) {
                if ($this->structure->isBusyUsername($value, 0)) {
                    $fail(__('calculator::structure.validate.username_not_unique'));
                }
            }]),
            'package_id' => array_merge(['required'], $nodeRules['package_id'])
        ];
    }

    public function sanitize()
    {
        return [
            'username' => 'trim',
        ];
    }

    public function attributes(): array
    {
        return [
            'structure_token' => __('calculator::structure.add_node.structure_token'),
            'top_node_id' => __('calculator::structure.add_node.top_node_id'),
            'position' => __('calculator::structure.add_node.position'),
            'sponsor_id' => __('calculator::structure.add_node.sponsor_id'),
            'username' => __('calculator::structure.add_node.username'),
            'package_id' => __('calculator::structure.add_node.package_id'),
        ];
    }

    public function messages()
    {
        return [
            'username.regex' => __('calculator::structure.validate.username_regex')
        ];
    }

    public function getDto(): NodeCreateData
    {
        $validated = $this->validated();

        return NodeCreateData::from([
            'structure' => $this->structure,
            'top_node_id' => $validated['top_node_id'],
            'position' => $validated['position'],
            'sponsor_id' => $validated['sponsor_id'] ?? $validated['top_node_id'],
            'username' => $validated['username'] ?? null,
            'package_id' => $validated['package_id']
        ]);
    }
}
