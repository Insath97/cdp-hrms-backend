<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AttendanceSettingsService;
use App\Services\AttendanceRuleService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register()
    {
        $this->app->singleton(AttendanceSettingsService::class, function ($app) {
            return new AttendanceSettingsService();
        });

        $this->app->singleton(AttendanceRuleService::class, function ($app) {
            return new AttendanceRuleService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
