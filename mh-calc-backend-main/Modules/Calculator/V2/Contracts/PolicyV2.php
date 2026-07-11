<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: доменный объект версии политики (полный план MH, деньги — integer USD-центы,
 * ставки — integer basis points, PV — decimal(18,6)).
 *
 * Заготовка (пустой value-object): наполняет T01 — он единственный владелец типа
 * (плюс policy_version_id / config_hash, которые обязаны попадать в снапшоты
 * расчётов T04/T06–T11). Все остальные задачи ТОЛЬКО потребляют объект через
 * PolicyVersionResolver и не заводят собственных типов политики.
 *
 * Контракт: amendments MF-5 (docs/specs/2026-07-12-mh-full-plan-gateA-amendments.md).
 */
class PolicyV2
{
    // Наполняется T01 (v2_policy_versions: id, code, status draft|active|retired,
    // valid_from/valid_to, config JSON, config_hash sha256).
}
