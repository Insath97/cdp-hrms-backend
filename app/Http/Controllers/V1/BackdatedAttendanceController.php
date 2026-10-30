<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BackdatedClockRequest;
use App\Http\Requests\EditAttendanceRequest;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class BackdatedAttendanceController extends Controller
{
    private const BACKDATED_GEOFENCE_LABEL = 'Backdated / Manual';

    /**
     * Store OR edit a clock-in for any date (past, today, or future).
     * Public endpoint — no authentication required.
     */
    public function clockIn(BackdatedClockRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            if (!isset($data['clock_in'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'clock_in is required.',
                ], 422);
            }

            $date = $data['date'];

            $user = $this->resolveUser($data);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee/user not found.',
                ], 404);
            }

            $existing = Attendance::where('user_id', $user->id)
                ->whereDate('date', $date)
                ->first();

            $clockInDateTime = Carbon::parse($date . ' ' . $data['clock_in']);

            [$lateMinutes, $exceedsGrace, $gracePeriod] = $this->calculateLate($date, $clockInDateTime);

            if ($existing) {
                $existing->clock_in = $clockInDateTime;
                $existing->late_minutes = $lateMinutes;
                $existing->exceeds_grace_period = $exceedsGrace;
                $existing->grace_period_applied = $gracePeriod;
                $existing->status = $data['status'] ?? 'present';

                $this->applyClockInFields($existing, $data, $request);
                $this->applyTimestamps($existing, $data);

                // Recalculate working hours if clock-out already exists
                if ($existing->clock_out) {
                    $existing->working_hours = Attendance::calculateWorkingHours($clockInDateTime, $existing->clock_out);
                }

                $existing->save();
                $attendance = $existing;
                $httpStatus = 200;
                $message = 'Clock-in updated successfully';
            } else {
                $attendance = new Attendance([
                    'user_id' => $user->id,
                    'employee_id' => $user->employee?->id,
                    'date' => $date,
                    'clock_in' => $clockInDateTime,
                    'status' => $data['status'] ?? 'present',
                    'late_minutes' => $lateMinutes,
                    'exceeds_grace_period' => $exceedsGrace,
                    'grace_period_applied' => $gracePeriod,
                ]);

                $this->applyClockInFields($attendance, $data, $request);
                $this->applyTimestamps($attendance, $data);

                $attendance->save();
                $httpStatus = 201;
                $message = 'Clock-in recorded successfully';
            }

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => $attendance->load('employee', 'user'),
            ], $httpStatus);
        } catch (\Throwable $th) {
            Log::error('Failed to save clock-in', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to save clock-in',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Store OR edit a clock-out for any date (past, today, or future).
     * Public endpoint — no authentication required.
     */
    public function clockOut(BackdatedClockRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            if (!isset($data['clock_out'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'clock_out is required.',
                ], 422);
            }

            $date = $data['date'];

            $user = $this->resolveUser($data);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee/user not found.',
                ], 404);
            }

            $attendance = Attendance::where('user_id', $user->id)
                ->whereDate('date', $date)
                ->first();

            if (!$attendance || !$attendance->clock_in) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No clock-in found for this date. Record the clock-in first.',
                ], 404);
            }

            $isUpdate = (bool) $attendance->clock_out;

            $clockOutDateTime = Carbon::parse($date . ' ' . $data['clock_out']);
            $attendance->clock_out = $clockOutDateTime;
            $attendance->status = $data['status'] ?? 'present';

            $this->applyClockOutFields($attendance, $data, $request);
            $this->applyTimestamps($attendance, $data);

            // Build both ends on the record's own date (handles overnight shifts)
            $clockInDate = $attendance->date instanceof Carbon
                ? $attendance->date->toDateString()
                : $date;
            $start = Carbon::parse($clockInDate . ' ' . $attendance->clock_in->format('H:i:s'));
            $end = $clockOutDateTime;
            if ($end < $start) {
                $end->addDay();
            }

            $attendance->working_hours = Attendance::calculateWorkingHours($start, $end);
            $attendance->save();

            return response()->json([
                'status' => 'success',
                'message' => $isUpdate
                    ? 'Clock-out updated successfully'
                    : 'Clock-out recorded successfully',
                'data' => $attendance->load('employee', 'user'),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to save clock-out', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to save clock-out',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Edit the full attendance of a particular day (clock-in, clock-out, geofencing,
     * IP, status, and created_at/updated_at together).
     * Public endpoint — no authentication required.
     */
    public function edit(EditAttendanceRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $editableFields = [
                'clock_in', 'clock_out', 'status',
                'in_latitude', 'in_longitude', 'in_geofence_name', 'in_ipAddress',
                'out_latitude', 'out_longitude', 'out_geofence_name', 'out_ipAddress',
                'created_at', 'updated_at',
            ];
            $hasEditable = collect($editableFields)->contains(fn ($field) => isset($data[$field]));

            if (!$hasEditable) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Provide at least one field to edit (clock_in, clock_out, geofencing, status, or timestamps).',
                ], 422);
            }

            $date = $data['date'];

            $user = $this->resolveUser($data);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee/user not found.',
                ], 404);
            }

            $attendance = Attendance::where('user_id', $user->id)
                ->whereDate('date', $date)
                ->first();

            $isNew = false;
            if (!$attendance) {
                $attendance = new Attendance([
                    'user_id' => $user->id,
                    'employee_id' => $user->employee?->id,
                    'date' => $date,
                ]);
                $isNew = true;
            }

            $gracePeriod = AttendanceSetting::getIntSetting('grace_period_minutes', 5);
            $officeStartStr = AttendanceSetting::getSetting('office_start_time', '09:00:00');

            // Apply clock_in if provided (recalculates late fields)
            if (isset($data['clock_in'])) {
                $clockInDateTime = Carbon::parse($date . ' ' . $data['clock_in']);
                $attendance->clock_in = $clockInDateTime;

                [$lateMinutes, $exceedsGrace, $gracePeriod] = $this->calculateLate($date, $clockInDateTime);
                $attendance->late_minutes = $lateMinutes;
                $attendance->exceeds_grace_period = $exceedsGrace;
                $attendance->grace_period_applied = $gracePeriod;
            }

            // Apply clock_out if provided
            if (isset($data['clock_out'])) {
                $attendance->clock_out = Carbon::parse($date . ' ' . $data['clock_out']);
            }

            // Apply geofencing / IP / status when provided
            $this->applyClockInFields($attendance, $data, $request, false);
            $this->applyClockOutFields($attendance, $data, $request, false);

            if (isset($data['status'])) {
                $attendance->status = $data['status'];
            }

            // Recalculate working hours when both ends are known
            if ($attendance->clock_in && $attendance->clock_out) {
                $attendance->working_hours = Attendance::calculateWorkingHours(
                    $attendance->clock_in,
                    $attendance->clock_out
                );
            }

            $this->applyTimestamps($attendance, $data);

            if ($isNew) {
                $attendance->status = $attendance->status ?? 'present';
            }

            $attendance->save();

            return response()->json([
                'status' => 'success',
                'message' => $isNew
                    ? 'Attendance recorded successfully'
                    : 'Attendance updated successfully',
                'data' => $attendance->load('employee', 'user'),
            ], $isNew ? 201 : 200);
        } catch (\Throwable $th) {
            Log::error('Failed to edit attendance', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to edit attendance',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve the target user from user_id or employee_code.
     */
    private function resolveUser(array $data): ?User
    {
        if (!empty($data['user_id'])) {
            return User::with('employee')->find($data['user_id']);
        }

        if (!empty($data['employee_code'])) {
            $employee = Employee::where('employee_code', $data['employee_code'])->first();
            if (!$employee) {
                return null;
            }

            return User::with('employee')->where('employee_id', $employee->id)->first()
                ?? $employee->user;
        }

        return null;
    }

    /**
     * Auto-calculate late_minutes / exceeds_grace_period from attendance settings.
     *
     * @return array{0: int, 1: int, 2: int} [lateMinutes, exceedsGrace, gracePeriod]
     */
    private function calculateLate(string $date, Carbon $clockInDateTime): array
    {
        $gracePeriod = AttendanceSetting::getIntSetting('grace_period_minutes', 5);
        $officeStartStr = AttendanceSetting::getSetting('office_start_time', '09:00:00');
        $officeStartDateTime = Carbon::parse($date . ' ' . $officeStartStr);

        $lateMinutes = 0;
        $exceedsGrace = 0;
        if (!Holiday::isHoliday($date) && $clockInDateTime > $officeStartDateTime) {
            $lateMinutes = (int) $officeStartDateTime->diffInMinutes($clockInDateTime);
            $exceedsGrace = ($lateMinutes > $gracePeriod) ? 1 : 0;
        }

        return [$lateMinutes, $exceedsGrace, $gracePeriod];
    }

    /**
     * Apply clock-in side fields (geofence / IP) from the request when provided;
     * otherwise fall back to request IP + backdated label.
     */
    private function applyClockInFields(Attendance $attendance, array $data, $request, bool $forceDefaults = true): void
    {
        if (isset($data['in_ipAddress'])) {
            $attendance->in_ipAddress = $data['in_ipAddress'];
        } elseif ($forceDefaults) {
            $attendance->in_ipAddress = $request->ip();
        }

        if (isset($data['in_geofence_name'])) {
            $attendance->in_geofence_name = $data['in_geofence_name'];
        } elseif ($forceDefaults) {
            $attendance->in_geofence_name = self::BACKDATED_GEOFENCE_LABEL;
        }

        if (isset($data['in_latitude'])) {
            $attendance->in_latitude = $data['in_latitude'];
        }
        if (isset($data['in_longitude'])) {
            $attendance->in_longitude = $data['in_longitude'];
        }
    }

    /**
     * Apply clock-out side fields (geofence / IP) from the request when provided;
     * otherwise fall back to request IP + backdated label.
     */
    private function applyClockOutFields(Attendance $attendance, array $data, $request, bool $forceDefaults = true): void
    {
        if (isset($data['out_ipAddress'])) {
            $attendance->out_ipAddress = $data['out_ipAddress'];
        } elseif ($forceDefaults) {
            $attendance->out_ipAddress = $request->ip();
        }

        if (isset($data['out_geofence_name'])) {
            $attendance->out_geofence_name = $data['out_geofence_name'];
        } elseif ($forceDefaults) {
            $attendance->out_geofence_name = self::BACKDATED_GEOFENCE_LABEL;
        }

        if (isset($data['out_latitude'])) {
            $attendance->out_latitude = $data['out_latitude'];
        }
        if (isset($data['out_longitude'])) {
            $attendance->out_longitude = $data['out_longitude'];
        }
    }

    /**
     * Apply created_at / updated_at when provided (manual timestamp entry like SQL).
     */
    private function applyTimestamps(Attendance $attendance, array $data): void
    {
        if (isset($data['created_at'])) {
            $attendance->created_at = Carbon::parse($data['created_at']);
        }
        if (isset($data['updated_at'])) {
            $attendance->updated_at = Carbon::parse($data['updated_at']);
        }
    }
}
