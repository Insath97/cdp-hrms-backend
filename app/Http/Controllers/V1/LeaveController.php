<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLeaveRequest;
use App\Http\Requests\UpdateLeaveRequest;
use App\Models\Leave;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class LeaveController extends Controller implements HasMiddleware
{
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
            $leave = Leave::create($data);

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
            $leave->update($data);

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

            $leave->update([
                'status' => 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

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

            $leave->update([
                'status' => 'rejected',
                'rejected_by' => Auth::id(),
                'rejected_at' => now(),
                'reject_reason' => $request->reject_reason,
            ]);

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
