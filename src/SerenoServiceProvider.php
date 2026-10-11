<?php

namespace Sereno\Laravel;

use Illuminate\Support\ServiceProvider;
use Sereno\Laravel\Logging\CreateSerenoLogger;

class SerenoServiceProvider extends ServiceProvider
{
    public const DEFAULT_URL = null;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sereno.php', 'sereno');

        if (config('logging.channels.sereno') === null) {
            config(['logging.channels.sereno' => [
                'driver' => 'custom',
                'via' => CreateSerenoLogger::class,
                'level' => config('sereno.level'),
            ]]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/sereno.php' => config_path('sereno.php')], 'sereno-config');
        }
    }
}
