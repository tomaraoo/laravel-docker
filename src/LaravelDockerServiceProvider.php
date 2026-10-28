<?php

declare(strict_types=1);

namespace Rft\LaravelDocker;

use Illuminate\Support\ServiceProvider;
use Rft\LaravelDocker\Console\InstallDockerScriptsCommand;

final class LaravelDockerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-docker.php', 'laravel-docker');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-docker.php' => $this->app->configPath('laravel-docker.php'),
        ], 'laravel-docker-config');

        $this->commands([
            InstallDockerScriptsCommand::class,
        ]);
    }
}
