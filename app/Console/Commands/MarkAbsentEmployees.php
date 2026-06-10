<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MarkAbsentEmployees extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:mark-absent {date? : Date in Y-m-d format (defaults to yesterday)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-mark staff employees who did not clock in and have no approved leave as Absent (No Pay)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->argument('date') ?? Carbon::yesterday()->toDateString();

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error("Invalid date format. Use Y-m-d (e.g. 2026-06-10).");
            return Command::FAILURE;
        }

        $this->info("Processing absent marking for: {$date}");

        // Skip holidays
        if (Holiday::isHoliday($date)) {
            $this->info("Skipped: {$date} is a public holiday.");
            return Command::SUCCESS;
        }

        // Resolve the "Absent (No Pay)" leave type
        $absentNoPayTypeId = AttendanceSetting::getIntSetting('absent_no_pay_leave_type_id');
        $leaveType = $absentNoPayTypeId ? LeaveType::find($absentNoPayTypeId) : null;

        if (!$leaveType) {
            $this->error('Absent (No Pay) leave type is not configured. Set "absent_no_pay_leave_type_id" in Attendance Settings.');
            return Command::FAILURE;
        }

        $staffUsers = User::where('user_type', 'staff')
            ->where('is_active', true)
            ->where('can_login', true)
            ->with('employee')
            ->get();

        $markedCount  = 0;
        $skippedCount = 0;
        $year         = Carbon::parse($date)->year;

        foreach ($staffUsers as $user) {
            if (!$user->employee_id) {
                $skippedCount++;
                continue;
            }

            // Skip if attendance record already exists
            $existingAttendance = Attendance::where('user_id', $user->id)
                ->whereDate('date', $date)
                ->first();

            if ($existingAttendance) {
                $skippedCount++;
                continue;
            }

            // Skip if the user has an approved leave that covers this date
            $hasApprovedLeave = Leave::where(function ($q) use ($user) {
                    $q->where('employee_id', $user->employee_id)
                      ->orWhere('user_id', $user->id);
                })
                ->where('status', 'approved')
                ->where('from_date', '<=', $date)
                ->where('to_date', '>=', $date)
                ->exists();

            if ($hasApprovedLeave) {
                $skippedCount++;
                continue;
            }

            // 1. Create attendance record
            Attendance::create([
                'user_id'       => $user->id,
                'employee_id'   => $user->employee_id,
                'date'          => $date,
                'clock_in'      => null,
                'clock_out'     => null,
                'working_hours' => 0,
                'status'        => 'absent',
            ]);

            // 2. Create pre-approved leave record
            Leave::create([
                'user_id'       => $user->id,
                'employee_id'   => $user->employee_id,
                'leave_type_id' => $leaveType->id,
                'from_date'     => $date,
                'to_date'       => $date,
                'reason'        => 'Auto-marked: absent without leave request.',
                'status'        => 'approved',
                'approved_by'   => null,
                'approved_at'   => now(),
            ]);

            // 3. Deduct from leave balance
            $balance = LeaveBalance::firstOrCreate(
                [
                    'leave_type_id' => $leaveType->id,
                    'employee_id'   => $user->employee_id,
                    'year'          => $year,
                ],
                [
                    'user_id'   => $user->id,
                    'allocated' => $leaveType->default_allocation,
                    'used'      => 0,
                    'balance'   => $leaveType->default_allocation,
                ]
            );

            $balance->used    += 1;
            $balance->balance  = $balance->allocated - $balance->used;
            $balance->save();

            $markedCount++;
            $this->line("  ✓ Marked absent: {$user->name} (ID: {$user->id})");
        }

        $this->info("Done. Marked: {$markedCount}, Skipped: {$skippedCount}");

        Log::info('attendance:mark-absent command completed', [
            'date'    => $date,
            'marked'  => $markedCount,
            'skipped' => $skippedCount,
        ]);

        return Command::SUCCESS;
    }
}
