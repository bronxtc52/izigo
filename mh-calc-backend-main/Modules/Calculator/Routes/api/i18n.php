<?php

use Illuminate\Support\Facades\Route;

// Block C — C4 i18n routes (заполняется в Волне C).
//
// Контракт C4 (Gate-A): покрыть все фронтовые ключи переводами; бэк-locales вне
// скоупа. Если потребуется серверная отдача оверрайдов — admin (web.admin +
// calculator.role:owner) на запись, публичное/cabinet-чтение по необходимости.
// Миграции C4 — диапазон 2026_06_22_0540xx (translation_overrides).
//
// Живые роуты (если понадобятся) добавляются здесь, в группах admin./cabinet. (api/v1).
