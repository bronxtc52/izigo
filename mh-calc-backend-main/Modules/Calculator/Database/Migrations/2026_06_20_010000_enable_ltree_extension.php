<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Включаем расширение ltree для нормализованной генеалогии (materialized path).
 * Только PostgreSQL. На Azure Flexible требуется также добавить `ltree` в
 * server-параметр `azure.extensions` (иначе CREATE EXTENSION упадёт по правам).
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS ltree');
        }
    }

    public function down(): void
    {
        // Расширение не удаляем: может использоваться другими объектами; снос — вручную.
    }
};
