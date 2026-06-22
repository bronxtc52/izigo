<?php

use Illuminate\Support\Facades\Route;

// Block C — C2 helpdesk routes (заполняется в Волне B).
//
// Контракт C2 (Gate-A): тикеты + сообщения, polling 5–8с, без priority/вложений.
// Партнёр создаёт/читает свои тикеты (cabinet, telegram.auth); агенты отвечают
// (admin, web.admin + calculator.role:owner,support).
// Миграции C2 — диапазон 2026_06_22_0550xx (tickets, ticket_messages).
//
// Живые роуты добавляются здесь, в существующих группах cabinet./admin. (api/v1).
