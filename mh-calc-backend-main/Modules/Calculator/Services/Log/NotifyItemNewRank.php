<?php

namespace Modules\Calculator\Services\Log;

use Modules\Calculator\Models\Rank;

class NotifyItemNewRank extends NotifyItem
{
    public function __construct(public int $oldRankId, public int $newRankId, public string $locale)
    {
        parent::__construct($oldRankId < $newRankId, $this->setMessage());
    }

    private function setMessage():string
    {
        $rankMap = Rank::getMap($this->locale);

        if (!$this->oldRankId)
        {
            $result = __('calculator::marketing.new_rank_notify_good_from_zero', [
                'new_rank' => $rankMap[$this->newRankId]->name,
            ]);
        }
        elseif (!$this->newRankId)
        {
            $result = __('calculator::marketing.new_rank_notify_bad_to_zero');
        }
        elseif ($this->oldRankId < $this->newRankId)
        {
            $result = __('calculator::marketing.new_rank_notify_good', [
                'old_rank' => $rankMap[$this->oldRankId]->name,
                'new_rank' => $rankMap[$this->newRankId]->name,
            ]);
        }
        else
        {
            $result = __('calculator::marketing.new_rank_notify_bad', [
                'old_rank' => $rankMap[$this->oldRankId]->name,
                'new_rank' => $rankMap[$this->newRankId]->name,
            ]);

        }
        return $result;
    }
}
