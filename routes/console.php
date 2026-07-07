<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send birthday notifications daily at 8 AM
Schedule::command('notifications:birthdays')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Auto-mark absent employees daily at 9 PM (runs only if auto_mark_absent setting is enabled)
Schedule::command('attendance:mark-absent')
    ->dailyAt('21:00')
    ->when(fn () => filter_var(
        \App\Models\AttendanceSetting::getSetting('auto_mark_absent', false),
        FILTER_VALIDATE_BOOLEAN
    ))
    ->withoutOverlapping()
    ->runInBackground();

