<?php
// app/Console/Commands/RecalculateAttendanceLateStatus.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Attendance;
use App\Services\AttendanceSettingsService;
use Carbon\Carbon;

class RecalculateAttendanceLateStatus extends Command
{
    protected $signature = 'attendance:recalculate-late {--date= : Specific date to recalculate}';
    protected $description = 'Recalculate late minutes and grace period for attendance records';

    public function handle(AttendanceSettingsService $settingsService)
    {
        $query = Attendance::query();

        if ($date = $this->option('date')) {
            $query->whereDate('date', $date);
            $this->info("Recalculating for date: {$date}");
        } else {
            $this->info("Recalculating for all attendance records...");
        }

        $attendances = $query->get();
        $count = 0;
        $updated = 0;

        foreach ($attendances as $attendance) {
            if (!$attendance->clock_in) {
                continue;
            }

            // Get settings directly from the service
            $officeStartTimeStr = $settingsService->get('office_start_time', '09:00:00');
            $gracePeriod = $settingsService->getGracePeriod();

            $dateStr = $attendance->date instanceof Carbon ? $attendance->date->toDateString() : $attendance->date;
            $officeStart = Carbon::parse($dateStr . ' ' . $officeStartTimeStr);
            $clockIn = $attendance->clock_in instanceof Carbon ? $attendance->clock_in : Carbon::parse($attendance->clock_in);

            $lateMinutes = $clockIn->diffInMinutes($officeStart, false);
            $exceedsGracePeriod = $lateMinutes > $gracePeriod ? 1 : 0;

            // Only update if values have changed
            if ($attendance->late_minutes != ($lateMinutes > 0 ? $lateMinutes : 0) ||
                $attendance->exceeds_grace_period != $exceedsGracePeriod ||
                $attendance->grace_period_applied != $gracePeriod) {

                $attendance->late_minutes = $lateMinutes > 0 ? $lateMinutes : 0;
                $attendance->exceeds_grace_period = $exceedsGracePeriod;
                $attendance->grace_period_applied = $gracePeriod;
                $attendance->save();
                $updated++;

                $this->info("Updated attendance ID {$attendance->id}: late_minutes={$attendance->late_minutes}, exceeds_grace={$exceedsGracePeriod}");
            }

            $count++;

            if ($count % 10 == 0) {
                $this->info("Processed {$count} records, updated {$updated}");
            }
        }

        $this->info("Completed! Processed {$count} attendance records, updated {$updated}.");
    }
}
