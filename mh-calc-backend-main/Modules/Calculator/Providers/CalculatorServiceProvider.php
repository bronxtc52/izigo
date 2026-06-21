<?php

namespace Modules\Calculator\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\Calculator\Console\RemoveOldEmptyStructuresCommand;
use Modules\Calculator\Http\Middleware\SetCalculatorUserMiddleware;
use Modules\Calculator\Http\Middleware\CheckUserTokenMiddleware;
use Modules\Calculator\Http\Middleware\ResolveTelegramMember;
use Modules\Calculator\Http\Middleware\RoleMiddleware;
use Modules\Calculator\Services\CalculatorAuthService;
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
        $router->aliasMiddleware('calculator.role', RoleMiddleware::class);
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
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            RemoveOldEmptyStructuresCommand::class
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
            $schedule->command('calculator:remove-old-empty')->daily();
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
