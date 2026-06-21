<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC-записи (Фаза 4, S8). Один актуальный статус на участника. Документы приходят через
 * Telegram Passport и хранятся как есть (зашифрованный payload/ссылки) — расшифровка и
 * реальная верификация/AML — Фаза 5. Здесь только intake + ручной аппрув + пороговый гейт.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kyc_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete();
            $table->string('source', 24)->default('telegram_passport');
            $table->text('documents')->nullable(); // зашифрованный (encrypted:array) payload Passport / ссылки
            $table->string('review_status', 12)->default('pending'); // pending|approved|rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();

            $table->index('review_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_records');
    }
};
