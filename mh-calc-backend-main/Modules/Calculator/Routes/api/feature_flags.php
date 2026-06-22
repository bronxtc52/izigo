<?php

use Illuminate\Support\Facades\Route;

// Block C — C3 feature_flags routes (заполняется в Волне A).
//
// Контракт C3 (Gate-A): флаги заранее выключены (deny-by-default), чтение через
// cabinet-auth (telegram.auth), управление owner-only (admin, web.admin +
// calculator.role:owner).
// Миграции C3 — диапазон 2026_06_22_0520xx (feature_flags).
//
// Живые роуты добавляются здесь, в существующих группах cabinet./admin. (api/v1).
