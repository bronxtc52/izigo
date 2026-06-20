<?php

namespace Modules\Calculator\Dto\Resource;

use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

class PersonalVolume extends EventData
{
    /**
     * @param float $bv
     * @param float $pv
     */
    public function __construct(public float $bv, public float $pv)
    {
    }

    public function getDetail(): string
    {
        return __("calculator::marketing.details_volumes_personal", [
            'pv_amount' => CurrencyFormatter::pv($this->pv),
            'bv_amount' => CurrencyFormatter::bv($this->bv)
        ]);
    }
}
