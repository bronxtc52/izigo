<?php

namespace Modules\Calculator\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Calculator\Models\Structure\Structure;

class CanBeSponsorRule implements ValidationRule
{
    private Structure $structure;
    private ?int $top_node_id = null;

    public function __construct(Structure $structure, ?int $top_node_id)
    {
        $this->structure = $structure;
        $this->top_node_id = $top_node_id;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->top_node_id == 0 && $value == 0) {
            return;
        }
        if (!$this->structure->hasParentOrSelf($this->top_node_id, $value)) {
            $fail(__('calculator::structure.validate.sponsor_not_valid'));
        }
    }
}
