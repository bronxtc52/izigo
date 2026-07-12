<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: lifetime PV бинарных сторон участника «за весь период» (BR-RANK-002) —
 * вход порогов малой ветки лестницы статусов (T05). Объявлен T05 (план Гейта A),
 * канонический имплементатор — volume-слой T03 (v2_pv_lots):
 * lifetime = Σ pv_original − Σ pv_reversed (consumed/matched лоты ВКЛЮЧЕНЫ,
 * аннулированные grace/reversal — исключены), все binary descendants (DEC-055).
 *
 * Значения — decimal-строки (18,6): PV в money-контуре не проходит через float.
 */
interface BinaryVolumeReaderInterface
{
    public function leftLifetimePv(int $memberId, \DateTimeInterface $asOf): string;

    public function rightLifetimePv(int $memberId, \DateTimeInterface $asOf): string;
}
