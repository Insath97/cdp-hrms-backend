<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send birthday notifications daily at 8:01 AM (Windows scheduler runs at minute 01)
Schedule::command('notifications:birthdays')
    ->dailyAt('08:01')
    ->withoutOverlapping()
    ->runInBackground();

// Close sessions still open after office end time + 1 hour (e.g. end 5 PM -> expires 6 PM, swept at 6:01)
Schedule::command('attendance:expire-sessions')
    ->hourlyAt(1)
    ->withoutOverlapping()
    ->runInBackground();

// Auto-mark absent employees daily at 9:01 PM (runs only if auto_mark_absent setting is enabled)
Schedule::command('attendance:mark-absent')
    ->dailyAt('21:01')
    ->when(fn () => filter_var(
        \App\Models\AttendanceSetting::getSetting('auto_mark_absent', false),
        FILTER_VALIDATE_BOOLEAN
    ))
    ->withoutOverlapping()
    ->runInBackground();

