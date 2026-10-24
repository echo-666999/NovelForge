<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
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
            // 全测试环境阻断未显式伪造的HTTP，避免网关测试意外产生真实费用。
            Http::preventStrayRequests();
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
