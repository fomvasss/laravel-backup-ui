<?php

namespace Fomvasss\LaravelBackupUi;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Fomvasss\LaravelBackupUi\Console\Commands\RestoreBackupCommand;

class BackupUiServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/backup-ui.php', 'backup-ui');
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'backup-ui');

        $this->publishes([
            __DIR__ . '/../config/backup-ui.php' => config_path('backup-ui.php'),
        ], 'backup-ui-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/backup-ui'),
        ], 'backup-ui-views');

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RestoreBackupCommand::class,
            ]);
        }
    }

    protected function registerRoutes()
    {
        Route::group([
            'prefix' => config('backup-ui.route_prefix', 'backup'),
            'middleware' => config('backup-ui.middleware', ['web']),
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        });
    }
}
