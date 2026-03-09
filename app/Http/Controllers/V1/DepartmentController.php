<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DepartmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Department Index', only: ['index', 'show']),
            new Middleware('permission:Department Create', only: ['store']),
            new Middleware('permission:Department Update', only: ['update']),
            new Middleware('permission:Department Delete', only: ['destroy']),
            new Middleware('permission:Department Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Department::with(['head']);

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $departments = $query->paginate($perPage);

            Log::info('Departments index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'per_page']),
                'count' => $departments->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Departments retrieved successfully',
                'data' => $departments
            ], 200);
        } catch (\Throwable $th) {

            Log::error('Failed to retrieve departments', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve departments',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateDepartmentRequest $request)
    {
        try {
            $data = $request->validated();
            $department = Department::create($data);

            Log::info('Department created', [
                'user_id' => Auth::id(),
                'department_id' => $department->id,
                'department_name' => $department->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Department created successfully',
                'data' => $department
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create department',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $department = Department::with(['head', 'employees'])->find($id);

            if (!$department) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Department not found'
                ], 404);
            }

            Log::info('Department viewed', [
                'user_id' => Auth::id(),
                'department_id' => $department->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Department retrieved successfully',
                'data' => $department
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve department',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateDepartmentRequest $request, string $id)
    {
        try {
            $department = Department::find($id);

            if (!$department) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Department not found'
                ], 404);
            }

            $data = $request->validated();
            $department->update($data);

            Log::info('Department updated', [
                'user_id' => Auth::id(),
                'department_id' => $department->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Department updated successfully',
                'data' => $department
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update department',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $department = Department::find($id);

            if (!$department) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Department not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized department deletion attempt', [
                    'user_id' => Auth::id(),
                    'department_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete departments'
                ], 403);
            }

            $department->delete();

            Log::info('Department deleted', [
                'user_id' => Auth::id(),
                'department_id' => $id,
                'department_name' => $department->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Department deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete department',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $department = Department::find($id);

            if (!$department) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Department not found'
                ], 404);
            }

            $department->is_active = !$department->is_active;
            $department->save();

            Log::info('Department status toggled', [
                'user_id' => Auth::id(),
                'department_id' => $department->id,
                'new_status' => $department->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Department status updated successfully',
                'data' => [
                    'id' => $department->id,
                    'is_active' => $department->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle department status',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
