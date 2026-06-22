<?php

use Illuminate\Support\Facades\Route;

// Block C — C6 copartners routes (заполняется в Волне A).
//
// Контракт C6 (Gate-A): несколько записей со-партнёров без валидации суммы;
// админка read-only. Партнёр заводит/смотрит свои записи (cabinet, telegram.auth);
// админ только просмотр (admin, web.admin + calculator.role:owner,finance,support).
// Миграции C6 — диапазон 2026_06_22_0530xx (member_copartners).
//
// Живые роуты добавляются здесь, в существующих группах cabinet./admin. (api/v1).
