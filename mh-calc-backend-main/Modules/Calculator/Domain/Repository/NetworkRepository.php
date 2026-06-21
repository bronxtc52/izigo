<?php

namespace Modules\Calculator\Domain\Repository;

use Modules\Calculator\Domain\Model\Network;

/**
 * Источник сети для движка. Реализация (Eloquent) живёт вне ядра и маппит
 * таблицы members → чистый Network. Ядро зависит только от этого интерфейса.
 */
interface NetworkRepository
{
    public function load(): Network;
}
