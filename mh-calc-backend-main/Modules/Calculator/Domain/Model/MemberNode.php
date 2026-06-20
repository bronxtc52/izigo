<?php

namespace Modules\Calculator\Domain\Model;

use Modules\Calculator\Domain\ValueObject\Pv;

/**
 * Узел сети (чистый, без фреймворка). Два графа: placement (parent/children, бинар)
 * и sponsorship (sponsor по ЛП). Объёмы — calc-состояние, заполняются движком.
 */
final class MemberNode
{
    public ?MemberNode $parent = null;
    public ?MemberNode $sponsor = null;
    /** @var MemberNode[] бинарные дети (до 2) */
    public array $children = [];

    public int $rankId = 0;

    public Pv $pvPersonal;
    public Pv $pvGroup;
    /** Бинарный объём ветки у родителя; сокращается при выплате бинар-бонуса (flush). */
    public Pv $parentBinaryPv;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $sponsorId,
        public readonly ?int $packageId,
    ) {
        $this->pvPersonal = Pv::zero();
        $this->pvGroup = Pv::zero();
        $this->parentBinaryPv = Pv::zero();
    }

    public function isRoot(): bool
    {
        return $this->parent === null;
    }
}
