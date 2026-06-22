<?php

use Illuminate\Support\Facades\Route;

// Block C — C5 exports routes (заполняется в Волне A).
//
// Контракт C5 (Gate-A): полный PII-режим — маска по умолчанию + reveal owner-only
// + аудит; экспорт JSON + CSV. PII = telegram_username / payout_details / KYC.
// Только admin (web.admin); reveal/экспорт PII — calculator.role:owner; обычные
// сводки — owner,finance,support по характеру данных.
// Миграций C5 нет (читает существующие таблицы; аудит через admin_audit_log).
//
// Живые роуты добавляются здесь, в существующей группе admin. (api/v1).
