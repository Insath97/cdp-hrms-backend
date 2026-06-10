<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AbsentMarkingController extends Controller
{
    /**
     * Mark employees who did not clock in and have no approved leave as Absent (No Pay).
     *
     * POST /api/v1/admin/attendances/mark-absent
     * Body: { "date": "YYYY-MM-DD" }  — optional, defaults to yesterday
     */
    public function markAbsent(Request $request)
    {
        try {
            $request->validate([
                'date' => 'nullable|date_format:Y-m-d',
            ]);

            $date = $request->input('date', Carbon::yesterday()->toDateString());

            // Prevent marking future dates
            if (Carbon::parse($date)->isFuture()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot mark absent for a future date.',
                ], 422);
            }

            // Skip if the date is a public holiday
            if (Holiday::isHoliday($date)) {
                return response()->json([
                    'status'  => 'success',
                    'message' => "Skipped: {$date} is a public holiday.",
                    'data'    => ['marked_count' => 0, 'date' => $date],
                ], 200);
            }

            // Resolve the "Absent (No Pay)" leave type from settings
            $absentNoPayTypeId = AttendanceSetting::getIntSetting('absent_no_pay_leave_type_id');
            $leaveType = $absentNoPayTypeId ? LeaveType::find($absentNoPayTypeId) : null;

            if (!$leaveType) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Absent (No Pay) leave type is not configured. Please set "absent_no_pay_leave_type_id" in Attendance Settings.',
                ], 422);
            }

            // Get all active staff users who have an employee record with a primary phone
            $staffUsers = User::where('user_type', 'staff')
                ->where('is_active', true)
                ->where('can_login', true)
                ->with('employee')
                ->get();

            $markedCount   = 0;
            $skippedCount  = 0;
            $markedUsers   = [];

            $approverId    = Auth::id() ?? 1;
            $approverEmpId = optional(optional(User::with('employee')->find($approverId))->employee)->id;

            foreach ($staffUsers as $user) {
                if (!$user->employee_id) {
                    $skippedCount++;
                    continue;
                }

                // Skip if attendance record already exists for that date (clocked in, on leave, etc.)
                $existingAttendance = Attendance::where('user_id', $user->id)
                    ->whereDate('date', $date)
                    ->first();

                if ($existingAttendance) {
                    $skippedCount++;
                    continue;
                }

                // Skip if the user has any leave (pending or approved) that covers this date
                $hasLeave = Leave::where(function ($q) use ($user) {
                        $q->where('employee_id', $user->employee_id)
                          ->orWhere('user_id', $user->id);
                    })
                    ->whereIn('status', ['pending', 'approved'])
                    ->where('from_date', '<=', $date)
                    ->where('to_date', '>=', $date)
                    ->exists();

                if ($hasLeave) {
                    $skippedCount++;
                    continue;
                }

                // ── Mark as Absent (No Pay) ──────────────────────────────────

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

                // 2. Create a pre-approved leave record of type "Absent (No Pay)"
                Leave::create([
                    'user_id'      => $user->id,
                    'employee_id'  => $user->employee_id,
                    'leave_type_id' => $leaveType->id,
                    'from_date'    => $date,
                    'to_date'      => $date,
                    'reason'       => 'Auto-marked: absent without leave request.',
                    'status'       => 'approved',
                    'approved_by'  => $approverEmpId,
                    'approved_at'  => now(),
                ]);

                // 3. Deduct 1 day from leave balance (no-pay leave still tracked)
                $balanceKey = [
                    'leave_type_id' => $leaveType->id,
                    'employee_id'   => $user->employee_id,
                    'year'          => Carbon::parse($date)->year,
                ];

                $balance = LeaveBalance::firstOrCreate(
                    $balanceKey,
                    [
                        'user_id'    => $user->id,
                        'allocated'  => $leaveType->default_allocation,
                        'used'       => 0,
                        'balance'    => $leaveType->default_allocation,
                    ]
                );

                $balance->used    += 1;
                $balance->balance  = $balance->allocated - $balance->used;
                $balance->save();

                $markedCount++;
                $markedUsers[] = [
                    'user_id'     => $user->id,
                    'employee_id' => $user->employee_id,
                    'name'        => $user->name,
                ];
            }

            Log::info('Absent (No Pay) marking completed', [
                'triggered_by' => Auth::id(),
                'date'         => $date,
                'marked'       => $markedCount,
                'skipped'      => $skippedCount,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "Absent marking complete for {$date}.",
                'data'    => [
                    'date'          => $date,
                    'marked_count'  => $markedCount,
                    'skipped_count' => $skippedCount,
                    'marked_users'  => $markedUsers,
                ],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Failed to mark absent employees', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to mark absent employees.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }
}
