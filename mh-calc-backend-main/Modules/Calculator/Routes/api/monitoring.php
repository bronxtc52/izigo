<?php

use Illuminate\Support\Facades\Route;

// Block C — C7 monitoring routes (заполняется в Волне B).
//
// Контракт C7 (Gate-A): просмотр outbox + failed_jobs, owner-only. Только admin
// (web.admin + calculator.role:owner), read-only.
// Миграций C7 нет (читает notification_outbox из C1 и стандартный failed_jobs).
//
// Живые роуты добавляются здесь, в существующей группе admin. (api/v1).
