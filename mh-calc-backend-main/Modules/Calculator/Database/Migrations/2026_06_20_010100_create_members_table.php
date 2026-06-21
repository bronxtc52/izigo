<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Реальные участники сети (members). Два графа:
 *  - placement (бинар): parent_id + position(left|right) + path(ltree, materialized path);
 *  - sponsorship (ЛП): sponsor_id.
 * path хранит materialized path по placement для быстрых subtree/ancestor-запросов
 * (BFS-спилловер, объём ветки). На pgsql — тип ltree + GIST; иначе — строка.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            // Идентичность платформы — Telegram. Email/CalculatorUser больше не привязаны.
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->string('telegram_username')->nullable();
            $table->string('language')->nullable(); // user.language_code из Telegram
            $table->foreignId('sponsor_id')->nullable()
                ->constrained('members')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()
                ->constrained('members')->nullOnDelete();
            $table->string('position', 5)->nullable(); // left|right (null для корня)
            $table->foreignId('package_id')->nullable()
                ->constrained('calculator_packages')->nullOnDelete();
            $table->unsignedBigInteger('rank_id')->nullable();
            $table->string('name')->nullable();
            $table->string('ref_code', 16)->unique();
            $table->string('status', 16)->default('registered'); // registered|active
            $table->unsignedInteger('version')->default(0); // оптимистичная блокировка
            $table->timestamps();

            // У родителя не более одной ноги каждой стороны.
            $table->unique(['parent_id', 'position']);
            $table->index('sponsor_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE members ADD COLUMN path ltree');
            DB::statement('CREATE INDEX members_path_gist ON members USING GIST (path)');
            // Единственный корень: обычный unique(parent_id, position) не ловит NULL-дубли.
            DB::statement('CREATE UNIQUE INDEX members_single_root ON members ((parent_id IS NULL)) WHERE parent_id IS NULL');
        } else {
            Schema::table('members', function (Blueprint $table) {
                $table->string('path')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
