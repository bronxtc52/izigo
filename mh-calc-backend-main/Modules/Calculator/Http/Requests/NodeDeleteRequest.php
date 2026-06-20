<?php

namespace Modules\Calculator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Calculator\Dto\NodeDeleteData;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Models\Structure\Node;
use Modules\Calculator\Models\Structure\Structure;

/**
 * @urlParam node_id int required
 * @bodyParam structure_token string required
 *
 */
class NodeDeleteRequest extends FormRequest
{
    private ?Structure $structure;
    private ?Node $node;

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
        if (!$this->node || $this->node->id == 1) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function getDto(): NodeDeleteData
    {
        return NodeDeleteData::from([
            'structure' => $this->structure,
            'node_id' => $this->node->id
        ]);
    }
}
