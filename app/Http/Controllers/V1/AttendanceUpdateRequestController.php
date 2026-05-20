<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Attendance;
use App\Models\AttendanceUpdateRequest;
use App\Http\Controllers\V1\AttendanceController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AttendanceUpdateRequestController extends Controller
{
    /**
     * Display a listing of the requests.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $employee = $user->employee;
            $query = AttendanceUpdateRequest::with(['attendance', 'user', 'employee', 'manager']);

            // If not Super Admin, show only requests for current manager
            if (!$user->hasRole('Super Admin')) {
                if (!$employee) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Employee record not found'
                    ], 404);
                }
                $query->where('manager_id', $employee->id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $requests = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

            return response()->json([
                'status' => 'success',
                'message' => 'Requests retrieved successfully',
                'data' => $requests
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve attendance update requests', [
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve requests',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified request.
     */
    public function show(string $id)
    {
        try {
            $updateRequest = AttendanceUpdateRequest::with(['attendance', 'user', 'employee', 'manager', 'approvedBy', 'rejectedBy'])->find($id);

            if (!$updateRequest) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Request not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $updateRequest
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve request',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Approve the request and apply changes to Attendance.
     */
    public function approve(string $id)
    {
        try {
            $updateRequest = AttendanceUpdateRequest::find($id);

            if (!$updateRequest) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Request not found'
                ], 404);
            }

            if ($updateRequest->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Request is already ' . $updateRequest->status
                ], 400);
            }

            $user = Auth::user();
            $employee = $user->employee;

            // Authorization check
            if (!$user->hasRole('Super Admin') && $updateRequest->manager_id !== ($employee->id ?? null)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized to approve this request'
                ], 403);
            }

            $attendance = $updateRequest->attendance;
            
            // Apply requested changes
            $data = [];
            if ($updateRequest->requested_clock_in) {
                $data['clock_in'] = $updateRequest->requested_clock_in;
            }
            if ($updateRequest->requested_clock_out) {
                $data['clock_out'] = $updateRequest->requested_clock_out;
            }

            // Recalculate late minutes and working hours
            $gracePeriod = \App\Models\AttendanceSetting::getIntSetting('grace_period_minutes', 5);
            $officeStartStr = \App\Models\AttendanceSetting::getSetting('office_start_time', '09:00:00');
            
            if (isset($data['clock_in'])) {
                $clockIn = Carbon::parse($data['clock_in']);
                $dateStr = $attendance->date instanceof Carbon ? $attendance->date->toDateString() : $attendance->date;
                $officeStartDateTime = Carbon::parse($dateStr . ' ' . $officeStartStr);

                if ($clockIn > $officeStartDateTime) {
                    $lateMinutes = (int) $officeStartDateTime->diffInMinutes($clockIn);
                    $data['late_minutes'] = $lateMinutes;
                    $data['exceeds_grace_period'] = ($lateMinutes > $gracePeriod) ? 1 : 0;
                } else {
                    $data['late_minutes'] = 0;
                    $data['exceeds_grace_period'] = 0;
                }
            }

            if (isset($data['clock_out'])) {
                $clock_in = $data['clock_in'] ?? $attendance->clock_in;
                $data['working_hours'] = Attendance::calculateWorkingHours($clock_in, $data['clock_out']);
                $data['status'] = 'present';
            }

            $attendance->update($data);

            // Update request status
            $updateRequest->update([
                'status' => 'approved',
                'approved_by' => $employee->id ?? null,
                'approved_at' => now()
            ]);

            // Trigger rule processing
            $dateStr = ($attendance->date instanceof Carbon) ? $attendance->date->toDateString() : Carbon::parse($attendance->date)->toDateString();
            (new AttendanceController())->executeProcessRules($dateStr, $attendance->employee_id);

            Log::info('Attendance update request approved', [
                'request_id' => $updateRequest->id,
                'attendance_id' => $attendance->id,
                'approved_by' => Auth::id()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendance update request approved and changes applied',
                'data' => $attendance->load('employee', 'user')
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to approve attendance update request', [
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve request',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Reject the request.
     */
    public function reject(Request $request, string $id)
    {
        try {
            $updateRequest = AttendanceUpdateRequest::find($id);

            if (!$updateRequest) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Request not found'
                ], 404);
            }

            if ($updateRequest->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Request is already ' . $updateRequest->status
                ], 400);
            }

            $user = Auth::user();
            $employee = $user->employee;

            // Authorization check
            if (!$user->hasRole('Super Admin') && $updateRequest->manager_id !== ($employee->id ?? null)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized to reject this request'
                ], 403);
            }

            $updateRequest->update([
                'status' => 'rejected',
                'rejected_by' => $employee->id ?? null,
                'rejected_at' => now(),
                'rejection_reason' => $request->input('reason') // Save the rejection reason here
            ]);

            Log::info('Attendance update request rejected', [
                'request_id' => $updateRequest->id,
                'rejected_by' => Auth::id()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendance update request rejected'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject request',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
