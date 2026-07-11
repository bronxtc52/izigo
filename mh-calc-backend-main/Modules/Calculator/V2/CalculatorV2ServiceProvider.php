<?php

namespace Modules\Calculator\V2;

use Illuminate\Support\ServiceProvider;

/**
 * mh-full-plan: ЕДИНСТВЕННОЕ место DI-регистрации, команд и расписания V2-движка.
 * Подключён одной строкой (маркер «>>> V2») из CalculatorServiceProvider::register(),
 * чтобы задачи волн НЕ трогали горячий основной провайдер (карта рисков Гейта A, п.1).
 *
 * Каждая задача дописывает СВОЙ блок между маркерами «>>> V2 Txx» ниже — merge-train
 * разрешает конфликты тривиально. Миграции V2 живут в ОБЩЕМ каталоге
 * Modules/Calculator/Database/Migrations (слоты — docs/mh-full-plan-migration-ledger.md);
 * отдельный loadMigrationsFrom здесь НЕ добавлять.
 */
class CalculatorV2ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // >>> V2 T01: версии политики — singleton-сервис (per-request кэш резолва)
        $this->app->singleton(Services\PolicyVersionService::class);
        $this->app->bind(Contracts\PolicyVersionResolver::class, Services\PolicyVersionService::class);
        // <<< V2 T01

        // >>> V2 T02: bind Contracts\LedgerV2::class, Contracts\NsToOsTransfer::class
        // <<< V2 T02

        // >>> V2 T03: bind Contracts\PvLotService::class, Contracts\PaidOrderV2Pipeline::class (singleton)
        // <<< V2 T03

        // >>> V2 T04: bind Contracts\CalcPeriodService::class
        // <<< V2 T04
    }

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
    }

    /** Команды V2 (Console/). Владелец команд периодов/переводов — ТОЛЬКО T04 (amendments MF-6). */
    protected function registerCommands(): void
    {
        $this->commands([
            // >>> V2 T04: команды calc-v2:* (close-half-month, close-month, ns-os-transfer)
            // <<< V2 T04

            // >>> V2 T09: квартальная выплата глобального пула
            // <<< V2 T09
        ]);
    }

    /** Расписание V2 — по образцу основного провайдера (идемпотентность + withoutOverlapping). */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function () {
            // >>> V2 T04: schedule calc-v2:* (закрытия периодов; ns-os-transfer ежедневно
            //     с гейтом «месяц закрыт и откалиброван», amendments MF-4/MF-6)
            // <<< V2 T04
        });
    }
}
