<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLeaveTypeRequest;
use App\Http\Requests\UpdateLeaveTypeRequest;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class LeaveTypeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:LeaveType Index', only: ['index', 'show']),
            new Middleware('permission:LeaveType Create', only: ['store']),
            new Middleware('permission:LeaveType Update', only: ['update']),
            new Middleware('permission:LeaveType Delete', only: ['destroy']),
            new Middleware('permission:LeaveType Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = LeaveType::query();

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $leaveTypes = $query->paginate($perPage);

            Log::info('Leave types index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'per_page']),
                'count' => $leaveTypes->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave types retrieved successfully',
                'data' => $leaveTypes
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve leave types', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve leave types',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateLeaveTypeRequest $request)
    {
        try {
            $data = $request->validated();
            $leaveType = LeaveType::create($data);

            Log::info('Leave type created', [
                'user_id' => Auth::id(),
                'leave_type_id' => $leaveType->id,
                'leave_type_name' => $leaveType->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave type created successfully',
                'data' => $leaveType
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create leave type',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $leaveType = LeaveType::find($id);

            if (!$leaveType) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Leave type not found'
                ], 404);
            }

            Log::info('Leave type viewed', [
                'user_id' => Auth::id(),
                'leave_type_id' => $leaveType->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave type retrieved successfully',
                'data' => $leaveType
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve leave type',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateLeaveTypeRequest $request, string $id)
    {
        try {
            $leaveType = LeaveType::find($id);

            if (!$leaveType) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Leave type not found'
                ], 404);
            }

            $data = $request->validated();
            $leaveType->update($data);

            Log::info('Leave type updated', [
                'user_id' => Auth::id(),
                'leave_type_id' => $leaveType->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave type updated successfully',
                'data' => $leaveType
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update leave type',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $leaveType = LeaveType::find($id);

            if (!$leaveType) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Leave type not found'
                ], 404);
            }

            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized leave type deletion attempt', [
                    'user_id' => Auth::id(),
                    'leave_type_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete leave types'
                ], 403);
            }

            $leaveType->delete();

            Log::info('Leave type deleted', [
                'user_id' => Auth::id(),
                'leave_type_id' => $id,
                'leave_type_name' => $leaveType->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave type deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete leave type',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $leaveType = LeaveType::find($id);

            if (!$leaveType) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Leave type not found'
                ], 404);
            }

            $leaveType->is_active = !$leaveType->is_active;
            $leaveType->save();

            Log::info('Leave type status toggled', [
                'user_id' => Auth::id(),
                'leave_type_id' => $leaveType->id,
                'new_status' => $leaveType->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leave type status updated successfully',
                'data' => [
                    'id' => $leaveType->id,
                    'is_active' => $leaveType->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle leave type status',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getLeaveTypeList()
    {
        try {
            $leaveTypes = LeaveType::where('is_active', true)
                ->select('id', 'name', 'code')
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Leave types retrieved successfully',
                'data' => $leaveTypes
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve leave type list', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve leave types',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
