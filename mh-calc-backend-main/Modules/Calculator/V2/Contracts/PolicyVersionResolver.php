<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: резолвер версии политики. ЕДИНСТВЕННАЯ сигнатура доступа к политике для всех
 * задач волны (владелец реализации — T01, потребители — T02..T14 только через
 * этот интерфейс).
 *
 * Контракт: amendments MF-5 — forDate() возвращает доменный объект PolicyV2
 * (не array, не int); версия выбирается по дате события (paid_at / граница
 * периода), полуинтервал [valid_from, valid_to).
 */
interface PolicyVersionResolver
{
    /**
     * Версия политики, действующая на момент $at.
     *
     * @throws \RuntimeException реализация T01 бросает доменное исключение
     *                           (PolicyNotActiveException), если активной версии нет
     */
    public function forDate(\DateTimeInterface $at): PolicyV2;

    /** Шорткат: версия, действующая сейчас. */
    public function current(): PolicyV2;
}
