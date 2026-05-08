<?php
// app/Services/AttendanceRuleService.php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceRuleService
{
    protected $attendanceSettings;
    protected $gracePeriod;
    protected $shortLeaveDays;
    protected $halfDayDays;
    protected $absentDays;

    public function __construct()
    {
        $this->attendanceSettings = app(AttendanceSettingsService::class);
        $this->loadDynamicSettings();
    }

    protected function loadDynamicSettings()
    {
        $this->gracePeriod = $this->attendanceSettings->getGracePeriod();
        $this->shortLeaveDays = $this->attendanceSettings->getShortLeaveThreshold();
        $this->halfDayDays = $this->attendanceSettings->getHalfDayThreshold();
        $this->absentDays = $this->attendanceSettings->getAbsentThreshold();

        Log::info('Attendance rules loaded', [
            'grace_period' => $this->gracePeriod,
            'short_leave_days' => $this->shortLeaveDays,
            'half_day_days' => $this->halfDayDays,
            'absent_days' => $this->absentDays
        ]);
    }

    public function refreshSettings()
    {
        $this->loadDynamicSettings();
    }

    /**
     * Get consecutive late days (only counts days that exceeded grace period)
     */
    public function getConsecutiveLateDays($employeeId, $date)
    {
        $dateObj = Carbon::parse($date);
        $consecutiveCount = 0;
        $currentDate = $dateObj->copy()->subDay();
        $maxDaysToCheck = 30;

        Log::info('Checking consecutive late days', [
            'employee_id' => $employeeId,
            'start_date' => $dateObj->format('Y-m-d')
        ]);

        for ($i = 1; $i <= $maxDaysToCheck; $i++) {
            $checkDate = $currentDate->copy()->subDays($i - 1);

            $attendance = Attendance::where(function($q) use ($employeeId) {
                    $q->where('employee_id', $employeeId)
                      ->orWhere('user_id', $employeeId);
                })
                ->whereDate('date', $checkDate)
                ->first();

            if ($attendance && $attendance->exceeds_grace_period) {
                $consecutiveCount++;
                Log::info('Found consecutive late day', [
                    'date' => $checkDate->format('Y-m-d'),
                    'count' => $consecutiveCount
                ]);
            } else {
                // Break on first non-late day
                break;
            }
        }

        Log::info('Total consecutive late days found', [
            'employee_id' => $employeeId,
            'consecutive_count' => $consecutiveCount
        ]);

        return $consecutiveCount;
    }

    /**
     * Apply leave conversion rules
     */
    public function applyConsecutiveLateRules($employeeId, $date)
    {
        Log::info('applyConsecutiveLateRules called', [
            'employee_id' => $employeeId,
            'date' => $date->format('Y-m-d')
        ]);

        if (!$this->attendanceSettings->isAutoConversionEnabled()) {
            Log::info('Auto conversion is disabled');
            return;
        }

        // Get consecutive late count (excluding today)
        $consecutiveLateCount = $this->getConsecutiveLateDays($employeeId, $date);

        // Add today
        $totalConsecutive = $consecutiveLateCount + 1;

        Log::info('Consecutive count calculation', [
            'previous_days' => $consecutiveLateCount,
            'today_included' => $totalConsecutive,
            'short_leave_threshold' => $this->shortLeaveDays,
            'half_day_threshold' => $this->halfDayDays,
            'absent_threshold' => $this->absentDays
        ]);

        // Determine which rule applies
        $rule = null;

        if ($totalConsecutive >= $this->absentDays) {
            $rule = [
                'type' => 'absent',
                'threshold' => $this->absentDays,
                'leave_type_id' => $this->attendanceSettings->getAbsentLeaveTypeId(),
                'label' => 'Absent'
            ];
        } elseif ($totalConsecutive >= $this->halfDayDays) {
            $rule = [
                'type' => 'half_day',
                'threshold' => $this->halfDayDays,
                'leave_type_id' => $this->attendanceSettings->getHalfDayLeaveTypeId(),
                'label' => 'Half Day'
            ];
        } elseif ($totalConsecutive >= $this->shortLeaveDays) {
            $rule = [
                'type' => 'short_leave',
                'threshold' => $this->shortLeaveDays,
                'leave_type_id' => $this->attendanceSettings->getShortLeaveTypeId(),
                'label' => 'Short Leave'
            ];
        }

        if (!$rule) {
            Log::info('No rule matches current consecutive count', [
                'total_consecutive' => $totalConsecutive
            ]);
            return;
        }

        if (!$rule['leave_type_id']) {
            Log::warning('Rule matches but no leave type configured', [
                'rule_type' => $rule['type'],
                'message' => 'Please configure leave type for this rule in Attendance Settings'
            ]);
            return;
        }

        // Check if leave already exists for this date
        $existingLeave = Leave::where(function($q) use ($employeeId) {
                $q->where('employee_id', $employeeId)
                  ->orWhere('user_id', $employeeId);
            })
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->first();

        if ($existingLeave) {
            Log::info('Leave already exists for this date', [
                'leave_id' => $existingLeave->id,
                'date' => $date->format('Y-m-d')
            ]);
            return;
        }

        // Create the leave
        DB::beginTransaction();
        try {
            $employee = Employee::find($employeeId);
            if (!$employee) {
                $user = User::find($employeeId);
                if ($user && $user->employee) {
                    $employee = $user->employee;
                }
            }

            $leave = Leave::create([
                'employee_id' => $employeeId,
                'user_id' => $employee ? $employee->user_id : null,
                'leave_type_id' => $rule['leave_type_id'],
                'from_date' => $date->format('Y-m-d'),
                'to_date' => $date->format('Y-m-d'),
                'reason' => "Auto-converted from {$totalConsecutive} consecutive late days",
                'status' => 'approved',
                'approved_by' => 1,
                'approved_at' => now(),
                'is_auto_converted' => true,
                'consecutive_late_days' => $totalConsecutive
            ]);

            // Update attendance record
            $attendance = Attendance::where(function($q) use ($employeeId) {
                    $q->where('employee_id', $employeeId)
                      ->orWhere('user_id', $employeeId);
                })
                ->whereDate('date', $date)
                ->first();

            if ($attendance) {
                $attendance->converted_at = now();
                $attendance->converted_leave_type = $rule['type'];
                $attendance->status = "converted_to_{$rule['type']}";
                $attendance->save();
            }

            DB::commit();

            Log::info('SUCCESS: Late attendance converted to leave', [
                'employee_id' => $employeeId,
                'date' => $date->format('Y-m-d'),
                'conversion_type' => $rule['type'],
                'consecutive_days' => $totalConsecutive,
                'leave_id' => $leave->id,
                'leave_type_id' => $rule['leave_type_id']
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to convert late to leave', [
                'employee_id' => $employeeId,
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
