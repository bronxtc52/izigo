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
        $this->app->singleton(\Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service::class);
        $this->app->singleton(\Modules\Calculator\V2\Services\Wallet\AccountsPolicyV2::class);
        $this->app->singleton(\Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service::class);
        $this->app->singleton(\Modules\Calculator\V2\Services\Wallet\OrderAccountPaymentService::class);
        // Оба контракта реализует один сервис (операция НС→ОС — T02; команда/расписание — T04, MF-6).
        $this->app->bind(
            \Modules\Calculator\V2\Contracts\LedgerV2::class,
            \Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service::class,
        );
        $this->app->bind(
            \Modules\Calculator\V2\Contracts\NsToOsTransfer::class,
            \Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service::class,
        );
        // <<< V2 T02

        // >>> V2 T03: bind Contracts\PvLotService::class, Contracts\PaidOrderV2Pipeline::class (singleton)
        $this->app->bind(
            \Modules\Calculator\V2\Contracts\PvLotService::class,
            \Modules\Calculator\V2\Services\Volume\PvLotVolumeService::class,
        );
        $this->app->singleton(
            \Modules\Calculator\V2\Contracts\PaidOrderV2Pipeline::class,
            function ($app) {
                $pipeline = new \Modules\Calculator\V2\Services\PaidOrderV2PipelineImpl();
                // Порядок регистрации = порядок исполнения. T05/T07 дописывают свои шаги СЮДА.
                $pipeline->register($app->make(\Modules\Calculator\V2\Services\Volume\VolumeCaptureStep::class));

                return $pipeline;
            },
        );
        // <<< V2 T03

        // >>> V2 T04: bind Contracts\CalcPeriodService::class
        $this->app->singleton(Services\Periods\PeriodCalendar::class);
        $this->app->singleton(Services\Periods\PeriodService::class);
        $this->app->singleton(Services\Periods\SnapshotService::class);
        $this->app->singleton(Services\Periods\PeriodCloseStepRegistry::class);
        $this->app->singleton(Services\Periods\JobExecutionGuard::class);
        $this->app->singleton(Services\Periods\PeriodCloseService::class);
        $this->app->singleton(
            Contracts\CalcPeriodService::class,
            fn ($app) => $app->make(Services\Periods\PeriodCloseService::class),
        );
        // Null-дефолты handler-контрактов (bindIf: реальные биндинги T02/T09/T11
        // в их маркер-блоках перекрывают, T04 безопасно мерджится первым):
        $this->app->bindIf(Contracts\NsToOsTransfer::class, Services\Periods\NullNsToOsTransfer::class);
        $this->app->bindIf(Contracts\PoolCalibrationReader::class, Services\Periods\NullPoolCalibrationReader::class);
        $this->app->bindIf(Contracts\QuarterGlobalPayoutHandler::class, Services\Periods\NullQuarterGlobalPayoutHandler::class);
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
            // >>> V2 T02: ежедневное сгорание кредит-лотов ОС/БС
            \Modules\Calculator\V2\Console\WalletLotsExpireCommand::class,
            // <<< V2 T02

            // >>> V2 T04: команды calc-v2:* (close-half-month, close-month, ns-os-transfer)
            Console\PeriodsEnsureCommand::class,
            Console\HalfMonthCloseCommand::class,
            Console\NsToOsTransferCommand::class,
            Console\MonthCloseCommand::class,
            Console\QuarterPayoutCommand::class,
            // <<< V2 T04

            // >>> V2 T09: квартальная выплата глобального пула
            // <<< V2 T09
        ]);
    }

    /** Расписание V2 — по образцу основного провайдера (идемпотентность + withoutOverlapping). */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            // >>> V2 T02: сгорание лотов ежедневно 00:20 UTC (DEC-019 — без holiday shift);
            //     команда идемпотентна и no-op за выключенным mh_plan_v2_engine.
            $schedule->command('mh2:lots-expire')->dailyAt('00:20')->withoutOverlapping(30);
            // <<< V2 T02

            // >>> V2 T04: schedule calc-v2:* (закрытия периодов; ns-os-transfer ежедневно
            //     с гейтом «месяц закрыт и откалиброван», amendments MF-4/MF-6).
            //     Границы периодов и запуски — UTC (роадмап T04, DEC-019 — без переноса
            //     на праздники); флаг mh_plan_v2_periods дублируется внутри команд
            //     (deny-by-default, no-op при выключенном).
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            $flagOn = fn (): bool => $this->app
                ->make(\Modules\Calculator\Services\FeatureFlag\FeatureFlagService::class)
                ->isEnabled('mh_plan_v2_periods');
            $schedule->command('calc-v2:periods-ensure')->dailyAt('00:01')->withoutOverlapping(30)->when($flagOn);
            $schedule->command('calc-v2:half-month-close')->dailyAt('00:10')->withoutOverlapping(30)->when($flagOn);
            $schedule->command('calc-v2:ns-os-transfer')->dailyAt('00:20')->withoutOverlapping(30)->when($flagOn);
            $schedule->command('calc-v2:month-close')->dailyAt('00:30')->withoutOverlapping(30)->when($flagOn);
            $schedule->command('calc-v2:quarter-payout')->dailyAt('00:40')->withoutOverlapping(30)->when($flagOn);
            // <<< V2 T04
        });
    }
}
