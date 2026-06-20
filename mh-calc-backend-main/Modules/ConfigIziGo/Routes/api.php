<?php

use Illuminate\Support\Facades\Route;
use Modules\ConfigIziGo\Http\Controllers\ConfigIziGoController;


// Структура и пользователи
Route::get('/locales', [ConfigIziGoController::class, 'getLocales'])->name('locales');

