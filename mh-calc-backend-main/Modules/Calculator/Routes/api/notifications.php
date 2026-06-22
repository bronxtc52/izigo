<?php

use Illuminate\Support\Facades\Route;

// Block C — C1 notifications routes (заполняется в Волне A).
//
// Контракт C1 (Gate-A): NotificationService::enqueueToMember / enqueueForMembers,
// доставка inbox + Telegram, событие MVP = статус выплаты, рассылки owner+support.
// Миграции C1 — диапазон 2026_06_22_0510xx (см. docs/block-c-migration-ledger.md).
//
// Партнёрский inbox — внутри cabinet-группы (middleware telegram.auth).
// Админ-рассылки — внутри admin-группы (web.admin + calculator.role:owner,support).
//
// Живые роуты добавляются здесь, в существующих группах cabinet./admin. (api/v1).
