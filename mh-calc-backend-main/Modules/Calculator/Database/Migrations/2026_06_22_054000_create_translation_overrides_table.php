<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C4 (Block C): редактируемые переводы (i18n-оверрайды из админки). Sparse-таблица —
 * хранит ТОЛЬКО переопределённые строки; дефолты остаются в статических locale-JSON
 * фронта. Эффективный перевод = оверрайд поверх статики, иначе статика, иначе ключ.
 *
 * locale ∈ {az,kk,ky,mn,ru,uz}; key — i18next-ключ (напр. "featureFlags.saved");
 * value — переопределённая строка. UNIQUE(locale,key) гарантирует один оверрайд на
 * пару (соблюдается через upsert). updated_by — кто последний правил (nullOnDelete,
 * чтобы удаление участника не роняло оверрайд). Управление — owner-only (Gate-A п.69).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('locale');
            $table->string('key');
            $table->text('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['locale', 'key']);
            $table->index('locale');

            $table->foreign('updated_by')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_overrides');
    }
};
