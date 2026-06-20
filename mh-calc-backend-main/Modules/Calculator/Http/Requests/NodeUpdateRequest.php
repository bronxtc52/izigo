<?php

namespace Modules\Calculator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Calculator\Dto\NodeUpdateData;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Models\Package;
use Modules\Calculator\Models\Structure\Node;
use Modules\Calculator\Models\Structure\Structure;
use Modules\Calculator\Rules\CanBeSponsorRule;
use Modules\Calculator\Rules\NodeRuleSet;

/**
 * @urlParam node_id int required
 * @bodyParam structure_token string required
 * @bodyParam sponsor_id int required
 * @bodyParam username string
 * @bodyParam package_id int required
 */
class NodeUpdateRequest extends FormRequest
{
    private ?Structure $structure = null;
    private ?Node $node = null;

    public function bodyParameters()
    {
        return [
            'structure_token' => [
                'description' => 'Токен с доступом на редактирование структуры.',
                'required' => true,
                'example' => "18d486a6b0b47cd0..."
            ],
            'node_id' => [
                'description' => 'ID узла, который нужно удалить.',
                'required' => true,
                'example' => 12
            ],
            'sponsor_id' => [
                'description' => 'ID спонсора узла.',
                'required' => false,
                'example' => 3,
            ],
            'username' => [
                'description' => 'Имя пользователя узла.',
                'required' => false,
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

        $this->node = $this->structure->getNodeById($this->structure->getRoot(), $this->node_id);
        if (!$this->node) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        $nodeRules = resolve(NodeRuleSet::class)->rules();

        if (!$this->node) {
            return [];
        }

        return [

            'sponsor_id' => ['sometimes', 'integer', 'bail', resolve(CanBeSponsorRule::class, [
                'structure' => $this->structure,
                'top_node_id' => $this->node->parent_id,
            ])],

            'username' => array_merge(['sometimes'],
                $nodeRules['username'], [function ($attribute, $value, $fail) {
                if ($this->structure->isBusyUsername($value, $this->node->id)) {
                    $fail(__('calculator::structure.validate.username_not_unique'));
                }
            }]),

            'package_id' => array_merge(['required'], $nodeRules['package_id'])
        ];
    }

    public function attributes(): array
    {
        return [
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

    public function getDto(): NodeUpdateData
    {
        $validated = $this->validated();

        return NodeUpdateData::from([
            'structure' => $this->structure,
            'node_id' => $this->node->id,
            'sponsor_id' => $validated['sponsor_id'] ?? $this->node->sponsor_id,
            'username' => $validated['username'] ?? $this->node->name,
            'package_id' => $validated['package_id']
        ]);
    }
}
