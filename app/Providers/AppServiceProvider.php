<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('testing')) {
            try {
                $database = $this->app['db']->connection('pgsql')->selectOne('SELECT current_database() AS name')->name;
            } catch (\Throwable) {
                throw new \RuntimeException('Cannot verify the isolated testing database.');
            }
            if ($database !== 'novel_forge_test') {
                throw new \RuntimeException('Connected database is not novel_forge_test.');
            }
        }
    }
}
