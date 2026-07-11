<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T04: immutable-снапшоты входов прогонов (v2_calc_snapshots).
 * payload: policy_version+config_hash (T01), манифест оплат окна, границы периода;
 * секции расширяют close-steps T06/T09/T11 через SnapshotService::addSection().
 * Только created_at — update-пути не существует (guard в модели и сервисе).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_calc_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->unique();
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->foreign('run_id')->references('id')->on('v2_calc_runs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_calc_snapshots');
    }
};
