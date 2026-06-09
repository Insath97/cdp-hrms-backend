<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLeaveRequest;
use App\Http\Requests\UpdateLeaveRequest;
use App\Models\Leave;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Traits\FileUploadTrait;
use App\Traits\ActivityLogTrait;

class LeaveController extends Controller implements HasMiddleware
{
    use FileUploadTrait, ActivityLogTrait;
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Leave Index', only: ['index', 'show']),
            new Middleware('permission:Leave Create', only: ['store']),
            new Middleware('permission:Leave Update', only: ['update']),
            new Middleware('permission:Leave Delete', only: ['destroy']),
            new Middleware('permission:Leave Approve', only: ['approve']),
            new Middleware('permission:Leave Reject', only: ['reject']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Leave::with(['employee', 'leaveType', 'approvedBy', 'rejectedBy', 'user']);

            if ($request->has('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }

            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->has('year')) {
                $year = $request->year;
                $query->where(function($q) use ($year) {
                    $q->whereYear('from_date', $year)
                      ->orWhereYear('to_date', $year);
                });
            }

            if ($request->has('leave_type_id')) {
                $query->where('leave_type_id', $request->leave_type_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $query->whereHas('employee', function ($q) use ($request) {
                    $q->where('full_name', 'like', "%{$request->search}%");
                });
            }

            // Hierarchical approval filter
            if ($request->is('*pending-approvals*') || ($request->has('pending_my_approval') && $request->pending_my_approval == 'true')) {
                $user = Auth::user();
                if ($user && $user->employee_id) {
                    $employee = Employee::find($user->employee_id);
                    if ($employee) {
                        $responsibleIds = $employee->getResponsibleSubordinateIds();
                        $query->whereIn('employee_id', $responsibleIds)
                              ->where('status', 'pending');
                    }
                }
            }

            $leaves = $query->orderBy('created_at', 'desc')->paginate($perPage);

            Log::info('Leave requests index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['employee_id', 'leave_type_id', 'status', 'search', 'per_page']),
                'count' => $leaves->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave requests retrieved successfully',
                'data' => $leaves
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve leave requests', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve leave requests',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateLeaveRequest $request)
    {
        try {
            $data = $request->validated();
            $data['user_id'] = $data['user_id'] ?? Auth::id();
            
            $path = $this->handleFileUpload(
                $request,
                'medical_certificate',
                null,
                'leaves/medical',
                'cert_' . time()
            );

            if ($path) {
                $data['medical_certificate'] = $path;
            }
            
            $leave = Leave::create($data);

            $this->logActivity('CREATE', 'Leave', "Created leave request from {$leave->from_date} to {$leave->to_date}", $data);

            Log::info('Leave request created', [
                'user_id' => Auth::id(),
                'leave_id' => $leave->id,
                'employee_id' => $leave->employee_id,
                'from_date' => $leave->from_date,
                'to_date' => $leave->to_date
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave request created successfully',
                'data' => $leave->load(['employee', 'leaveType', 'user'])
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Failed to create leave request', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create leave request',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $leave = Leave::with(['employee', 'leaveType', 'approvedBy', 'rejectedBy', 'user'])->find($id);

            if (!$leave) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Leave request not found'
                ], 404);
            }

            Log::info('Leave request viewed', [
                'user_id' => Auth::id(),
                'leave_id' => $leave->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave request retrieved successfully',
                'data' => $leave
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve leave request', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve leave request',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateLeaveRequest $request, string $id)
    {
        try {
            $leave = Leave::find($id);

            if (!$leave) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Leave request not found'
                ], 404);
            }

            if (!$leave->isPending()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only pending leave requests can be updated'
                ], 422);
            }

            $data = $request->validated();
            
            $path = $this->handleFileUpload(
                $request,
                'medical_certificate',
                $leave->medical_certificate,
                'leaves/medical',
                'cert_' . time()
            );

            if ($path) {
                $data['medical_certificate'] = $path;
            }

            $leave->update($data);

            $this->logActivity('UPDATE', 'Leave', "Updated leave request from {$leave->from_date} to {$leave->to_date}", $data);

            Log::info('Leave request updated', [
                'user_id' => Auth::id(),
                'leave_id' => $leave->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave request updated successfully',
                'data' => $leave->load(['employee', 'leaveType', 'user'])
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update leave request', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update leave request',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $leave = Leave::find($id);

            if (!$leave) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Leave request not found'
                ], 404);
            }

            if (!$leave->isPending()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only pending leave requests can be deleted'
                ], 422);
            }

            $leave->delete();

            $this->logActivity('DELETE', 'Leave', "Deleted leave request from {$leave->from_date} to {$leave->to_date}");

            Log::info('Leave request deleted', [
                'user_id' => Auth::id(),
                'leave_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave request deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to delete leave request', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete leave request',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function approve(Request $request, string $id)
    {
        try {
            $leave = Leave::find($id);

            if (!$leave) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Leave request not found'
                ], 404);
            }

            if (!$leave->canApprove()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This leave request cannot be approved. It may have already been processed.'
                ], 422);
            }

            $user = Auth::user();
            $approverEmpId = $user ? $user->employee_id : null;

            $leave->update([
                'status' => 'approved',
                'approved_by' => $approverEmpId,
                'approved_at' => now(),
            ]);

            $this->logActivity('APPROVE', 'Leave', "Approved leave request (ID: {$leave->id})");

            // Deduction from LeaveBalance
            if ($leave->employee_id || $leave->user_id) {
                $fromDate = \Carbon\Carbon::parse($leave->from_date);
                $toDate = \Carbon\Carbon::parse($leave->to_date);
                
                $days = 0;
                $current = $fromDate->copy();
                while ($current <= $toDate) {
                    // Only count if it's not a holiday
                    if (!\App\Models\Holiday::isHoliday($current)) {
                        $days++;
                    }
                    $current->addDay();
                }

                if ($days === 0) {
                    // If all days are holidays, maybe don't deduct anything or return early?
                    // Usually the frontend should prevent this, but we'll set it to 0.
                }

                $leaveType = \App\Models\LeaveType::find($leave->leave_type_id);
                $deduction = $days;
                
                if ($leaveType) {
                    if (stripos($leaveType->calculation_unit, 'half') !== false) {
                        $deduction = $days * 0.5;
                    } elseif (stripos($leaveType->calculation_unit, 'hour') !== false || stripos($leaveType->name, 'short') !== false) {
                        $deduction = $days * 0.25; // Assumption for short leave
                    }
                }

                $balanceMatch = [
                    'leave_type_id' => $leave->leave_type_id,
                    'year' => date('Y')
                ];
                if ($leave->employee_id) {
                    $balanceMatch['employee_id'] = $leave->employee_id;
                } else {
                    $balanceMatch['user_id'] = $leave->user_id;
                }

                $balance = \App\Models\LeaveBalance::firstOrCreate(
                    $balanceMatch,
                    [
                        'allocated' => $leaveType ? $leaveType->default_allocation : 0,
                        'used' => 0,
                        'balance' => $leaveType ? $leaveType->default_allocation : 0
                    ]
                );

                $balance->used += $deduction;
                $balance->balance = $balance->allocated - $balance->used;
                $balance->save();

                // Update Attendance records to reflect the approved leave
                $isMedical = stripos($leaveType->name, 'medical') !== false || strtolower($leaveType->code) === 'ml';
                $statusToApply = $isMedical ? 'medical_leave' : 'leave';

                $currentApproveDate = \Carbon\Carbon::parse($leave->from_date);
                $endApproveDate = \Carbon\Carbon::parse($leave->to_date);

                while ($currentApproveDate <= $endApproveDate) {
                    $attendanceMatch = [
                        'date' => $currentApproveDate->format('Y-m-d')
                    ];
                    if ($leave->employee_id) {
                        $attendanceMatch['employee_id'] = $leave->employee_id;
                    } else {
                        $attendanceMatch['user_id'] = $leave->user_id;
                    }

                    \App\Models\Attendance::updateOrCreate(
                        $attendanceMatch,
                        [
                            'user_id' => $leave->user_id,
                            'employee_id' => $leave->employee_id,
                            'status' => $statusToApply,
                            'working_hours' => 0,
                            'clock_in' => null,
                            'clock_out' => null
                        ]
                    );
                    $currentApproveDate->addDay();
                }
            }

            Log::info('Leave request approved', [
                'user_id' => Auth::id(),
                'leave_id' => $leave->id,
                'employee_id' => $leave->employee_id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave request approved successfully',
                'data' => $leave->load(['employee', 'leaveType', 'approvedBy', 'user'])
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to approve leave request', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve leave request',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function reject(Request $request, string $id)
    {
        try {
            $request->validate([
                'reject_reason' => 'required|string|max:1000',
            ]);

            $leave = Leave::find($id);

            if (!$leave) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Leave request not found'
                ], 404);
            }

            if (!$leave->canReject()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This leave request cannot be rejected. It may have already been processed.'
                ], 422);
            }

            $user = Auth::user();
            $rejectorEmpId = $user ? $user->employee_id : null;

            $leave->update([
                'status' => 'rejected',
                'rejected_by' => $rejectorEmpId,
                'rejected_at' => now(),
                'reject_reason' => $request->reject_reason,
            ]);

            $this->logActivity('REJECT', 'Leave', "Rejected leave request (ID: {$leave->id}). Reason: {$request->reject_reason}", $request->only(['reject_reason']));

            Log::info('Leave request rejected', [
                'user_id' => Auth::id(),
                'leave_id' => $leave->id,
                'employee_id' => $leave->employee_id,
                'reason' => $request->reject_reason
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave request rejected successfully',
                'data' => $leave->load(['employee', 'leaveType', 'rejectedBy', 'user'])
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to reject leave request', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject leave request',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
