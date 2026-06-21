<?php

namespace Modules\Calculator\Domain\Repository;

use Modules\Calculator\Domain\Plan\Plan;

/**
 * Источник конфигурации маркетинг-плана (проценты/пороги/ранг-бонусы).
 * Реализация строит Plan из БД-настроек + дефолтов фабрики.
 */
interface PlanRepository
{
    public function load(): Plan;
}
