<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Birthday alerts are day-scoped. The command removes previous days' alerts
// and only creates today's alerts once per user, so it is safe to run hourly.
// This covers machines that are switched on after 08:01 (the old daily time).
Schedule::command('notifications:birthdays')
    ->hourlyAt(1)
    ->withoutOverlapping()
    ->runInBackground();

// Send work anniversary notifications daily at 8:02 AM
Schedule::command('notifications:anniversaries')
    ->dailyAt('08:02')
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

