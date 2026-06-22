<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аудит-лог админ-действий (веб-админка). Кто/что/над чем + снимок before→after.
 * Главное назначение — безопасность и разбор изменений маркетинг-плана (правка живого
 * комп-движка) и ролей/выплат. Append-only: записи не правятся.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('action', 64);        // напр. plan.update, role.assign, role.revoke
            $table->string('entity_type', 32);   // plan|member|withdrawal|product|kyc
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_log');
    }
};
