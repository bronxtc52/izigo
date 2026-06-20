<?php

namespace Modules\Calculator\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Calculator\Models\Structure\Structure;

class SponsorPositionRule implements ValidationRule
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
        if ($this->structure->isPositionBusy($this->top_node_id, $value)) {
            $fail(__('calculator::structure.validate.position_busy'));
        }
    }
}
