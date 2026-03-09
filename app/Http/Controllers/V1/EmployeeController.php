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
            new Middleware('permission:Employee Index', only: ['index', 'show']),
            new Middleware('permission:Employee Create', only: ['store']),
            new Middleware('permission:Employee Update', only: ['update']),
            new Middleware('permission:Employee Delete', only: ['destroy']),
            new Middleware('permission:Employee Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Employee::with(['department']);

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $employees = $query->paginate($perPage);

            Log::info('Employees index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'department_id', 'is_active', 'per_page']),
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
            $employee = Employee::create($data);

            Log::info('Employee created', [
                'user_id' => Auth::id(),
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name
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
            $employee = Employee::with(['department', 'leadsDepartment'])->find($id);

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

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized employee deletion attempt', [
                    'user_id' => Auth::id(),
                    'employee_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete employees'
                ], 403);
            }

            $employee->delete();

            Log::info('Employee deleted', [
                'user_id' => Auth::id(),
                'employee_id' => $id,
                'employee_name' => $employee->full_name
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
    public function getEmployeeList()
    {
        try {
            $employees = Employee::active()
                ->select('id', 'full_name', 'employee_id')
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
