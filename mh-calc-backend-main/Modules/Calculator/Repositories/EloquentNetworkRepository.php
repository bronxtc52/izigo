<?php

namespace Modules\Calculator\Repositories;

use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\Model\Network;
use Modules\Calculator\Domain\Repository\NetworkRepository;
use Modules\Calculator\Models\Member;

/**
 * Маппит таблицу members → чистый доменный Network для CompensationEngine.
 * Порядок постановки = возрастание id (родитель всегда создан раньше ребёнка),
 * что совпадает с Network::orderedById() и логикой движка.
 *
 * rankId узлов НЕ преднастраиваем — движок пересчитывает ранги с нуля при каждом
 * прогоне (как в golden-тестах ядра); пересчёт детерминированный.
 */
class EloquentNetworkRepository implements NetworkRepository
{
    public function load(): Network
    {
        $members = Member::query()
            ->orderBy('id')
            ->get(['id', 'name', 'sponsor_id', 'parent_id', 'package_id']);

        $network = new Network();
        $parentByChild = [];

        foreach ($members as $m) {
            $network->add(new MemberNode(
                id: (int) $m->id,
                name: $m->name ?? ('#' . $m->id),
                sponsorId: (int) ($m->sponsor_id ?? 0),
                packageId: $m->package_id !== null ? (int) $m->package_id : null,
            ));
            $parentByChild[(int) $m->id] = $m->parent_id !== null ? (int) $m->parent_id : null;
        }

        $network->link($parentByChild);

        return $network;
    }
}
