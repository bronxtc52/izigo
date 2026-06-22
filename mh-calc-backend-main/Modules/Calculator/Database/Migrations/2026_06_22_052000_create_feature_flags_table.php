<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C3 (Block C): рантайм фиче-флаги. Каждый флаг — пара key→enabled, заранее выключенная
 * (deny-by-default, см. Gate-A п.8). Чтение — cabinet-auth, управление — owner-only.
 * updated_by — кто последний переключил (для аудита; nullOnDelete, чтобы удаление участника
 * не роняло флаг).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
