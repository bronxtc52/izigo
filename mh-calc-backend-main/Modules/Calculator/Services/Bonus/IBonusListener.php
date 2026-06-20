<?php

namespace Modules\Calculator\Services\Bonus;

use Modules\Calculator\Models\Structure\Node;

interface IBonusListener
{
    public function onBonusPay(Node $node, float $bonusAmount, string $message): void;
}
