<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-hardening (B6): свой ключ идемпотентности у inbox. Раньше от дублей inbox защищала
 * только транзакция «outbox+inbox вместе» (unique у outbox откатывал обе вставки); при
 * bulk-постановке insertOrIgnore фазы разделены — без собственного unique повтор рассылки
 * достроил бы inbox-дубли к уже существующим outbox-записям. Nullable: событийные
 * уведомления без dedup-ключа ставятся как раньше (Postgres допускает много NULL в unique).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_inbox', function (Blueprint $table) {
            $table->string('dedup_key', 128)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('notification_inbox', function (Blueprint $table) {
            $table->dropColumn('dedup_key');
        });
    }
};
