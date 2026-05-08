<?php
// app/Providers/AttendanceServiceProvider.php

namespace App\Providers;

use App\Services\AttendanceSettingsService;
use Illuminate\Support\ServiceProvider;

class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AttendanceSettingsService::class, function ($app) {
            return new AttendanceSettingsService();
        });
    }

    public function boot(): void
    {
        //
    }
}
