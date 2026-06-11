<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Employee;
use App\Models\Attendance;
use App\Models\Holiday;
use Carbon\Carbon;

class MarkDailyAbsentees extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:mark-absentees {date?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark active employees as absent if they have no clock-in or leave record for the day';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Default to today if no date is passed
        $dateStr = $this->argument('date') ?? Carbon::today()->format('Y-m-d');
        $date = Carbon::parse($dateStr);

        // Check if date is today and current time is before 16:15
        $now = Carbon::now();
        if ($date->isToday() && $now->format('H:i:s') < '16:15:00') {
            $this->info("Date {$dateStr} is today and current time is before 16:15. Skipping absentee marking.");
            return 0;
        }

        $this->info("Checking attendance records for date: {$dateStr}");

        // 1. Skip holiday/weekend
        if (Holiday::isHoliday($date)) {
            $this->info("Date {$dateStr} is a holiday or weekend. Skipping absentee marking.");
            return 0;
        }

        // 2. Get all active employees
        $activeEmployees = Employee::where('is_active', true)
            ->where('employment_status', 'active')
            ->get();

        // 3. Get corresponding users keyed by employee_id
        $employeeIds = $activeEmployees->pluck('id');
        $users = \App\Models\User::whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id');

        $count = 0;
        foreach ($activeEmployees as $employee) {
            $user = $users->get($employee->id);
            if (!$user) {
                $this->warn("Skipping Employee ID {$employee->id} ({$employee->full_name}): No corresponding user account found.");
                continue;
            }

            // 3. Check if a record already exists for this user on this date
            $exists = Attendance::where('user_id', $user->id)
                ->whereDate('date', $dateStr)
                ->exists();

            // 4. If no record exists, check if they are on approved leave
            if (!$exists) {
                if ($employee->isOnLeave($dateStr)) {
                    $this->line("Employee {$employee->full_name} is on approved leave. Skipping.");
                    continue;
                }

                // 5. Mark them as absent
                Attendance::create([
                    'user_id'     => $user->id,
                    'employee_id' => $employee->id,
                    'date'        => $dateStr,
                    'status'      => 'absent',
                    'leave_taken' => 0.00,
                    'is_no_pay'   => true,
                    'remarks'     => 'Auto-marked absent: No clock-in or leave request found.'
                ]);

                // 6. Update Leave Balance for Unpaid/Absent Leave
                $absentLeaveTypeId = \App\Models\AttendanceSetting::getIntSetting('absent_leave_type_id');
                $leaveType = null;
                if ($absentLeaveTypeId) {
                    $leaveType = \App\Models\LeaveType::find($absentLeaveTypeId);
                }
                if (!$leaveType) {
                    $leaveType = \App\Models\LeaveType::where('code', 'NOPAY')
                        ->orWhere('code', 'ABSENT')
                        ->first();
                }

                if ($leaveType) {
                    $balance = \App\Models\LeaveBalance::firstOrCreate(
                        [
                            'employee_id'   => $employee->id,
                            'leave_type_id' => $leaveType->id,
                            'year'          => $date->year,
                        ],
                        [
                            'user_id'   => $user->id,
                            'allocated' => $leaveType->default_allocation,
                            'used'      => 0,
                            'balance'   => $leaveType->default_allocation,
                        ]
                    );

                    $balance->used += 1.00;
                    $balance->balance = $balance->allocated - $balance->used;
                    $balance->save();
                }

                $count++;
            }
        }

        $this->info("Successfully marked {$count} employees as absent for {$dateStr}.");
        return 0;
    }
}
