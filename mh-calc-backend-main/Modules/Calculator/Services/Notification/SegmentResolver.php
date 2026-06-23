<?php

namespace Modules\Calculator\Services\Notification;

use InvalidArgumentException;
use Modules\Calculator\Models\Member;

/**
 * C1 (Block C) — резолвер сегментов рассылки в множество member_id. Читает ТОЛЬКО
 * таблицу members (не движок бонусов). Поддерживаемые сегменты:
 *   - 'all'                         — все участники
 *   - 'by_status' + 'active'|'registered'
 *   - 'by_rank'   + '<rank_id>'
 */
class SegmentResolver
{
    public const SEGMENT_ALL = 'all';
    public const SEGMENT_BY_STATUS = 'by_status';
    public const SEGMENT_BY_RANK = 'by_rank';

    /**
     * @return array<int,int> member_id
     */
    public function resolve(string $segmentType, ?string $segmentValue = null): array
    {
        return $this->query($segmentType, $segmentValue)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function count(string $segmentType, ?string $segmentValue = null): int
    {
        return $this->query($segmentType, $segmentValue)->count();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function query(string $segmentType, ?string $segmentValue)
    {
        $q = Member::query();

        switch ($segmentType) {
            case self::SEGMENT_ALL:
                return $q;

            case self::SEGMENT_BY_STATUS:
                $status = (string) $segmentValue;
                if (!in_array($status, ['active', 'registered'], true)) {
                    throw new InvalidArgumentException('Недопустимый статус сегмента');
                }

                return $q->where('status', $status);

            case self::SEGMENT_BY_RANK:
                if ($segmentValue === null || $segmentValue === '' || !ctype_digit((string) $segmentValue)) {
                    throw new InvalidArgumentException('Не указан корректный rank_id сегмента');
                }

                return $q->where('rank_id', (int) $segmentValue);

            default:
                throw new InvalidArgumentException('Неизвестный тип сегмента');
        }
    }
}
