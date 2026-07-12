<?php

namespace Modules\Calculator\V2\Services\Status;

use Modules\Calculator\V2\Contracts\BinaryVolumeReaderInterface;
use Modules\Calculator\V2\Models\PvLot;

/**
 * T05: канонический имплементатор BinaryVolumeReaderInterface поверх v2_pv_lots
 * (T03): lifetime PV стороны = Σ pv_original − Σ pv_reversed по лотам с
 * occurred_at <= asOf. Matched/consumed лоты ВКЛЮЧЕНЫ («за весь период»,
 * BR-RANK-002), аннулированные grace/реверсы — исключены через pv_reversed.
 * Формула согласована с BranchStatsService::recompute (та же семантика lifetime).
 */
class PvLotBinaryVolumeReader implements BinaryVolumeReaderInterface
{
    public function leftLifetimePv(int $memberId, \DateTimeInterface $asOf): string
    {
        return $this->lifetime($memberId, PvLot::SIDE_LEFT, $asOf);
    }

    public function rightLifetimePv(int $memberId, \DateTimeInterface $asOf): string
    {
        return $this->lifetime($memberId, PvLot::SIDE_RIGHT, $asOf);
    }

    private function lifetime(int $memberId, string $side, \DateTimeInterface $asOf): string
    {
        $row = PvLot::query()
            ->where('owner_member_id', $memberId)
            ->where('side', $side)
            ->where('occurred_at', '<=', $asOf)
            ->selectRaw('COALESCE(SUM(pv_original), 0) - COALESCE(SUM(pv_reversed), 0) AS lifetime_pv')
            ->first();

        return (string) ($row->lifetime_pv ?? '0');
    }
}
