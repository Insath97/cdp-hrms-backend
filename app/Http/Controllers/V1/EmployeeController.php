<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class EmployeeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Employee Index', only: ['index', 'show', 'getEmployeeList']),
            new Middleware('permission:Employee Create', only: ['store']),
            new Middleware('permission:Employee Update', only: ['update', 'makePermanent', 'terminate']),
            new Middleware('permission:Employee Delete', only: ['destroy', 'forceDelete']),
            new Middleware('permission:Employee Restore', only: ['restore']),
            new Middleware('permission:Employee Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Employee::with(['department', 'designation', 'branch', 'reportingManager']);

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            if ($request->has('designation_id')) {
                $query->where('designation_id', $request->designation_id);
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('employment_status')) {
                $query->where('employment_status', $request->employment_status);
            }

            $employees = $query->paginate($perPage);

            Log::info('Employees index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'department_id', 'designation_id', 'branch_id', 'is_active', 'employment_status', 'per_page']),
                'count' => $employees->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employees retrieved successfully',
                'data' => $employees
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve employees', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve employees',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateEmployeeRequest $request)
    {
        try {
            $data = $request->validated();

            if (empty($data['employee_code'])) {
                $data['employee_code'] = Employee::generateNextEmployeeCode();
            }

            $employee = Employee::create($data);

            Log::info('Employee created', [
                'user_id' => Auth::id(),
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_code
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employee created successfully',
                'data' => $employee
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Failed to create employee', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create employee',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $employee = Employee::with([
                'department',
                'designation',
                'branch',
                'zonal',
                'region',
                'province',
                'reportingManager',
                'subordinates'
            ])->find($id);

            if (!$employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found'
                ], 404);
            }

            Log::info('Employee viewed', [
                'user_id' => Auth::id(),
                'employee_id' => $employee->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employee retrieved successfully',
                'data' => $employee
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve employee', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve employee',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateEmployeeRequest $request, string $id)
    {
        try {
            $employee = Employee::find($id);

            if (!$employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found'
                ], 404);
            }

            $data = $request->validated();
            $employee->update($data);

            Log::info('Employee updated', [
                'user_id' => Auth::id(),
                'employee_id' => $employee->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employee updated successfully',
                'data' => $employee
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update employee', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update employee',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $employee = Employee::find($id);

            if (!$employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found'
                ], 404);
            }

            if (!Auth::user()->hasRole('Super Admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete employees'
                ], 403);
            }

            $employee->delete();

            Log::info('Employee deleted', [
                'user_id' => Auth::id(),
                'employee_id' => $id,
                'employee_code' => $employee->employee_code
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employee deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to delete employee', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete employee',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $employee = Employee::find($id);

            if (!$employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found'
                ], 404);
            }

            $employee->is_active = !$employee->is_active;
            $employee->save();

            Log::info('Employee status toggled', [
                'user_id' => Auth::id(),
                'employee_id' => $employee->id,
                'new_status' => $employee->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employee status updated successfully',
                'data' => [
                    'id' => $employee->id,
                    'is_active' => $employee->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to toggle employee status', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle employee status',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function makePermanent(string $id)
    {
        try {
            $employee = Employee::find($id);

            if (!$employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found'
                ], 404);
            }

            $employee->update([
                'employee_type' => 'permanent',
                'permanent_at' => now(),
                'employment_status' => 'active',
                'is_active' => true
            ]);

            Log::info('Employee made permanent', [
                'user_id' => Auth::id(),
                'employee_id' => $employee->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employee status updated to permanent',
                'data' => $employee
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update employee to permanent', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update employee status',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function terminate(Request $request, string $id)
    {
        try {
            $request->validate([
                'termination_reason' => 'required|string|max:1000',
                'left_at' => 'sometimes|date'
            ]);

            $employee = Employee::find($id);

            if (!$employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found'
                ], 404);
            }

            $employee->update([
                'employment_status' => 'terminated',
                'is_active' => false,
                'termination_reason' => $request->termination_reason,
                'left_at' => $request->left_at ?? now()
            ]);

            Log::info('Employee terminated', [
                'user_id' => Auth::id(),
                'employee_id' => $employee->id,
                'reason' => $request->termination_reason
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employee terminated successfully',
                'data' => $employee
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to terminate employee', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to terminate employee',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function restore(string $id)
    {
        try {
            $employee = Employee::withTrashed()->find($id);

            if (!$employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found'
                ], 404);
            }

            if (!$employee->trashed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee is not deleted'
                ], 422);
            }

            $employee->restore();

            Log::info('Employee restored', [
                'user_id' => Auth::id(),
                'employee_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employee restored successfully',
                'data' => $employee
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to restore employee', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to restore employee',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function forceDelete(string $id)
    {
        try {
            $employee = Employee::withTrashed()->find($id);

            if (!$employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found'
                ], 404);
            }

            if (!Auth::user()->hasRole('Super Admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can permanently delete employees'
                ], 403);
            }

            $employee->forceDelete();

            Log::info('Employee permanently deleted', [
                'user_id' => Auth::id(),
                'employee_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Employee permanently deleted'
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to force delete employee', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to permanently delete employee',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getEmployeeList(Request $request)
    {
        try {
            $query = Employee::active();

            if ($request->has('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            $employees = $query->select('id', 'full_name', 'employee_code')
                ->orderBy('full_name', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Employees retrieved successfully',
                'data' => $employees
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve employee list', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve employees',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
