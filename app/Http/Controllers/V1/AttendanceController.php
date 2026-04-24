<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAttendanceRequest;
use App\Http\Requests\UpdateAttendanceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\Attendance;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AttendanceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Attendance Index', only: ['index', 'show']),
            new Middleware('permission:Attendance Create', only: ['store']),
            new Middleware('permission:Attendance Update', only: ['update', 'clockOut']),
            new Middleware('permission:Attendance Delete', only: ['destroy']),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Attendance::query();

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('employee_id')) {
                $query->byEmployee($request->employee_id);
            }

            if ($request->has('date')) {
                $query->byDate($request->date);
            }

            if ($request->has('from_date') && $request->has('to_date')) {
                $query->dateRange($request->from_date, $request->to_date);
            }

            $attendances = $query->with('employee', 'user')->paginate($perPage);

            Log::info('Attendances index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'employee_id', 'date', 'from_date', 'to_date', 'per_page']),
                'count' => $attendances->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendances retrieved successfully',
                'data' => $attendances
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve attendances', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve attendances',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateAttendanceRequest $request)
    {
        try {
            $data = $request->validated();

            Log::info('Store method executed', ['data' => $data]);

            // Calculate working hours if both clock_in and clock_out are provided
            if ($data['clock_in'] && isset($data['clock_out']) && $data['clock_out']) {
                $data['working_hours'] = Attendance::calculateWorkingHours($data['clock_in'], $data['clock_out']);
                $data['status'] = 'present';
            } elseif ($data['clock_in']) {
                $data['status'] = 'present';
            }

            $attendance = Attendance::create($data);

            Log::info('Attendance created', [
                'user_id' => Auth::id(),
                'attendance_id' => $attendance->id,
                'employee_id' => $attendance->employee_id,
                'user_id' => $attendance->user_id,
                'date' => $attendance->date,
                'clock_in' => $attendance->clock_in,
                'clock_out' => $attendance->clock_out,
                'working_hours' => $attendance->working_hours,
                'status' => $attendance->status,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendance created successfully',
                'data' => $attendance->load('employee', 'user')
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Failed to create attendance', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create attendance',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $attendance = Attendance::with('employee', 'user')->find($id);

            if (!$attendance) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Attendance not found'
                ], 404);
            }

            Log::info('Attendance viewed', [
                'user_id' => Auth::id(),
                'attendance_id' => $attendance->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendance retrieved successfully',
                'data' => $attendance->load('employee', 'user')
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve attendance', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve attendance',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAttendanceRequest $request, string $id)
    {
        try {
            $attendance = Attendance::find($id);

            if (!$attendance) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Attendance not found'
                ], 404);
            }

            $data = $request->validated();

            // Determine if user_id update is requested and whether it's safe
            $allowUserIdUpdate = false;
            $requestedUserId = $data['user_id'] ?? null;
            if ($requestedUserId) {
                if ($attendance->user_id === null || $attendance->user_id == $requestedUserId) {
                    $allowUserIdUpdate = true;
                } else {
                    // Check for conflict: another attendance for same user and date
                    $conflict = Attendance::where('user_id', $requestedUserId)
                        ->where('date', $attendance->date)
                        ->where('id', '<>', $attendance->id)
                        ->exists();

                    if ($conflict) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Another attendance exists for this user on the same date'
                        ], 409);
                    }

                    $allowUserIdUpdate = true;
                }
            }

            // Calculate working hours when clock_out is provided
            if (isset($data['clock_out']) && $data['clock_out']) {
                $clock_in = $data['clock_in'] ?? $attendance->clock_in;
                $data['working_hours'] = Attendance::calculateWorkingHours($clock_in, $data['clock_out']);
                $data['status'] = 'present';
            }

            // Remove user_id from mass-updated data to handle it explicitly
            if (isset($data['user_id'])) {
                unset($data['user_id']);
            }

            $attendance->update($data);

            if ($allowUserIdUpdate && $requestedUserId) {
                $attendance->user_id = $requestedUserId;
                $attendance->save();
            }

            Log::info('Attendance updated', [
                // 'user_id' => Auth::id(),
                'attendance_id' => $attendance->id,
                'updated_fields' => array_keys($data),
                'working_hours' => $attendance->working_hours,
                'status' => $attendance->status,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendance updated successfully',
                'data' => $attendance->load('employee', 'user')
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update attendance', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update attendance',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Clock out the current user's active attendance for today.
     */
    public function clockOut(Request $request)
    {
        try {
            $user = Auth::user();

            $attendance = Attendance::where('user_id', $user->id)
                ->whereDate('date', now()->toDateString())
                ->whereNull('clock_out')
                ->latest()
                ->first();

            if (!$attendance) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active attendance found for today'
                ], 404);
            }

            $attendance->clock_out = $request->input('clock_out', now()->format('H:i:s'));

            if ($attendance->clock_in) {
                $attendance->working_hours = Attendance::calculateWorkingHours($attendance->clock_in, $attendance->clock_out);
                $attendance->status = 'present';
            }

            $attendance->save();

            Log::info('Attendance clocked out', [
                'user_id' => Auth::id(),
                'attendance_id' => $attendance->id,
                'clock_out' => $attendance->clock_out,
                'working_hours' => $attendance->working_hours,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Clocked out successfully',
                'data' => $attendance->load('employee', 'user')
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to clock out attendance', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to clock out attendance',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $attendance = Attendance::find($id);

            if (!$attendance) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Attendance not found'
                ], 404);
            }

            $attendance->delete();

            Log::info('Attendance deleted', [
                'user_id' => Auth::id(),
                'attendance_id' => $id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendance deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to delete attendance', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete attendance',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
