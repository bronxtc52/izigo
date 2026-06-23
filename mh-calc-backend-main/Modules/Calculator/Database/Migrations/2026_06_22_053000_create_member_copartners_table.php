<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C6 (Block C): со-партнёры / наследники участника — ЧИСТО СПРАВОЧНЫЕ данные.
 * Партнёр сам ведёт несколько записей в своём профиле (cabinet, telegram.auth);
 * админка — read-only. НЕ влияет на деньги/дерево/движок/авторизацию.
 *
 * share_percent — справочная доля БЕЗ валидации суммы (контракт Gate-A п.15):
 * несколько записей разрешены, сумма долей не проверяется (по unique нет).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_copartners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->string('kind', 16)->default('copartner'); // copartner|heir
            $table->string('full_name', 160);
            $table->string('phone', 32)->nullable();
            $table->decimal('share_percent', 5, 2)->nullable(); // справочно, сумма НЕ валидируется
            $table->string('note')->nullable();
            $table->timestamps();

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_copartners');
    }
};
