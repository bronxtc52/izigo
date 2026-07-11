<?php

namespace Modules\Calculator\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\Calculator\Console\AutoshipRunCommand;
use Modules\Calculator\Console\ExpireLeadsCommand;
use Modules\Calculator\Console\OutboxDispatchCommand;
use Modules\Calculator\Console\PayoutsPollCommand;
use Modules\Calculator\Console\RemoveOldEmptyStructuresCommand;
use Modules\Calculator\Console\SchedulerHeartbeatCommand;
use Modules\Calculator\Console\TonPayPollCommand;
use Modules\Calculator\Http\Middleware\SetCalculatorUserMiddleware;
use Modules\Calculator\Http\Middleware\CheckUserTokenMiddleware;
use Modules\Calculator\Http\Middleware\EnsureFeatureFlag;
use Modules\Calculator\Http\Middleware\MockActivationEnabled;
use Modules\Calculator\Http\Middleware\ResolveTelegramMember;
use Modules\Calculator\Http\Middleware\RoleMiddleware;
use Modules\Calculator\Http\Middleware\WebAdminAuth;
use Modules\Calculator\Services\CalculatorAuthService;
use Modules\Calculator\Services\Payment\FakeGateway;
use Modules\Calculator\Services\Payment\FakeTonPayGateway;
use Modules\Calculator\Services\Payment\PaymentGateway;
use Modules\Calculator\Services\Payment\TonPayGateway;
use Modules\Calculator\Services\Payout\FakePayoutGateway;
use Modules\Calculator\Services\Payout\PayoutGateway;
use Modules\Calculator\Services\Payout\UsdtTonPayoutGateway;
use Modules\ConfigIziGo\Http\Middleware\SetLocale;
use Modules\ConfigIziGo\Providers\GetModulePath;

class CalculatorServiceProvider extends ServiceProvider
{
    use GetModulePath;

    protected string $moduleName = 'Calculator';

    protected string $moduleNameLower = 'calculator';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerMiddlewares();
        $this->registerFacades();
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom($this->path('Database/Migrations'));
    }

    private function registerMiddlewares():void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('api', SetCalculatorUserMiddleware::class);

        $router->aliasMiddleware('calculator.validate.token', CheckUserTokenMiddleware::class);
        $router->aliasMiddleware('telegram.auth', ResolveTelegramMember::class);
        $router->aliasMiddleware('web.admin', WebAdminAuth::class);
        $router->aliasMiddleware('calculator.role', RoleMiddleware::class);
        $router->aliasMiddleware('feature.flag', EnsureFeatureFlag::class);
        $router->aliasMiddleware('mock.activation', MockActivationEnabled::class);
    }

    private function registerFacades():void
    {
        $this->app->singleton('calculator-auth', function ($app) {
            return new CalculatorAuthService();
        });
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
        $this->registerPaymentGateway();
        $this->registerPayoutGateway();
        // >>> V2 (mh-full-plan): вся DI/команды/расписание V2 — ТОЛЬКО в V2-провайдере,
        // этот файл больше не трогать (анти-конфликтный каркас, см.
        // docs/mh-full-plan-migration-ledger.md).
        $this->app->register(\Modules\Calculator\V2\CalculatorV2ServiceProvider::class);
        // <<< V2
    }

    /** Шлюз on-chain выплат (Фаза 4): драйвер по config calculator.payout_gateway. */
    private function registerPayoutGateway(): void
    {
        $this->app->singleton(PayoutGateway::class, function ($app) {
            $cfg = $app['config'];

            return match ($cfg->get('calculator.payout_gateway', 'ton_usdt')) {
                'fake' => new FakePayoutGateway(),
                default => new UsdtTonPayoutGateway(
                    (string) $cfg->get('calculator.ton_payout_wallet_key', ''),
                    (string) $cfg->get('calculator.ton_payout_wallet_address', ''),
                    (string) $cfg->get('calculator.ton_api_base_url', ''),
                ),
            };
        });
    }

    /** Платёжный шлюз приёма (Фаза 4): драйвер по config calculator.payment_gateway. */
    private function registerPaymentGateway(): void
    {
        $this->app->singleton(PaymentGateway::class, function ($app) {
            $cfg = $app['config'];
            $secret = (string) $cfg->get('calculator.walletpay_webhook_secret', '');

            return match ($cfg->get('calculator.payment_gateway', 'ton_pay')) {
                'fake' => new FakeGateway($secret),
                'ton_pay_fake' => new FakeTonPayGateway(),
                // Wallet Pay полностью отключён (решение: приём — только TON Pay). Драйвер
                // и класс удалены; явный fail-closed, чтобы случайная конфигурация не молча
                // упала в дефолтный TonPay.
                'wallet_pay' => throw new \RuntimeException(
                    'Драйвер wallet_pay отключён и не используется — выберите ton_pay.'
                ),
                default => new TonPayGateway(
                    (string) $cfg->get('calculator.ton_merchant_address', ''),
                    (string) $cfg->get('calculator.ton_api_v3_base_url', ''),
                    (string) $cfg->get('calculator.ton_api_key', ''),
                    (string) $cfg->get('calculator.ton_usdt_jetton_master', ''),
                ),
            };
        });
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            RemoveOldEmptyStructuresCommand::class,
            AutoshipRunCommand::class,
            PayoutsPollCommand::class,
            TonPayPollCommand::class,
            OutboxDispatchCommand::class,
            ExpireLeadsCommand::class,
            SchedulerHeartbeatCommand::class,
        ]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function () {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);
            // B-5: прямой heartbeat живости планировщика. Ежеминутный тик оставляет свежую
            // метку; /api/health отдаёт 503, если она протухла (планировщик умер/завис).
            // Без withoutOverlapping — операция мгновенная, мьютекс тут только мешал бы.
            $schedule->command('scheduler:heartbeat')->everyMinute();
            $schedule->command('calculator:remove-old-empty')->daily();
            $schedule->command('commerce:autoship-run')->dailyAt('03:00')->withoutOverlapping(60);
            $schedule->command('commerce:payouts-poll')->everyThirtyMinutes()->withoutOverlapping(25);
            // приём ждёт подтверждения сети; withoutOverlapping — чтобы зависший toncenter-запрос
            // не наложился на следующий тик (schedule:work запускает schedule:run ежеминутно).
            // Явный TTL мьютекса (мин), чтобы упавший процесс не заблокировал полл надолго.
            $schedule->command('commerce:tonpay-poll')->everyMinute()->withoutOverlapping(5);
            // C1 (Block C): диспетчер outbox уведомлений — фон проекта = планировщик,
            // НЕ Laravel queue. TTL мьютекса 5 мин, чтобы зависший тик не блокировал.
            $schedule->command('notifications:outbox-dispatch')->everyMinute()->withoutOverlapping(5);
            // Открепление просроченных лидов: окно 7 дней, ежечасной гранулярности достаточно.
            $schedule->command('leads:expire')->hourly()->withoutOverlapping(10);
        });
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $this->loadTranslationsFrom($this->path('Resources/lang'), $this->moduleNameLower);
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([$this->path('Config/config.php') => config_path($this->moduleNameLower . '.php')], 'config');
        $this->mergeConfigFrom($this->path('Config/config.php'), $this->moduleNameLower);
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/' . $this->moduleNameLower);
        $sourcePath = $this->path('Resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->moduleNameLower . '-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);

        $componentNamespace = str_replace('/', '\\', config('modules.namespace') . '\\' . $this->moduleName . '\\' . config('modules.paths.generator.component-class.path'));
        Blade::componentNamespace($componentNamespace, $this->moduleNameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path . '/modules/' . $this->moduleNameLower)) {
                $paths[] = $path . '/modules/' . $this->moduleNameLower;
            }
        }

        return $paths;
    }
}
