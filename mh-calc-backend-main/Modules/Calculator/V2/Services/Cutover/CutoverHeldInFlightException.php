<?php

namespace Modules\Calculator\V2\Services\Cutover;

/**
 * mh-full-plan T15 (hardening): «деньги в полёте» (held_cents>0) обнаружены УЖЕ ВНУТРИ
 * транзакции cutover — под ACTIVATION_LOCK и lockForUpdate на кошельке. Закрывает TOCTOU-окно
 * пре-чека команды: вывод, созданный между быстрым пре-чеком и стартом транзакции, был бы
 * тихо расщеплён между V1 (held) и V2 (opening). Бросок этой ошибки откатывает ВСЮ миграцию
 * атомарно (ни лота, ни проводки, ни Bronze→100); команда ловит её и пишет abort в v2_cutover_log.
 */
class CutoverHeldInFlightException extends \RuntimeException
{
    public function __construct(
        public readonly int $heldCents,
        public readonly int $heldMembers,
        public readonly ?int $memberId = null,
    ) {
        $where = $memberId !== null ? " (участник {$memberId})" : '';
        parent::__construct(
            "Cutover прерван: обнаружены открытые выводы (held={$heldCents} центов у {$heldMembers} партнёров){$where} "
            . 'под локом внутри транзакции — миграция откатана атомарно. Разрулите выводы до cutover.',
        );
    }
}
