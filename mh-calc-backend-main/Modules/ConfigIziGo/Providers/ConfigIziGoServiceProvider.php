<?php

namespace Modules\ConfigIziGo\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\ConfigIziGo\Http\Middleware\SetCurrency;
use Modules\ConfigIziGo\Http\Middleware\SetLocale;

class ConfigIziGoServiceProvider extends ServiceProvider
{
    use GetModulePath;

    protected string $moduleName = 'ConfigIziGo';

    protected string $moduleNameLower = 'configizigo';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom($this->path('Database/Migrations'));
        $this->pushMiddleware();
    }

    /**
     * Add middlewares.
     *
     * @return void
     */
    protected function pushMiddleware(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('api', SetLocale::class);
        $router->pushMiddlewareToGroup('api', SetCurrency::class);
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
        // $this->commands([]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        // $this->app->booted(function () {
        //     $schedule = $this->app->make(Schedule::class);
        //     $schedule->command('inspire')->hourly();
        // });
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
