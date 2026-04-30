<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Letter;
use App\Models\Province;
use App\Models\Region;
use App\Models\User;
use App\Models\Zonal;
use Illuminate\Http\JsonResponse;

class TableCountController extends Controller
{
    /**
     * Get counts of all tables
     */
    public function getTableCounts(): JsonResponse
    {
        try {
            $counts = [
                'users' => User::count(),
                'provinces' => Province::count(),
                'zonals' => Zonal::count(),
                'regions' => Region::count(),
                'branches' => Branch::count(),
                'departments' => Department::count(),
                'designations' => Designation::count(),
                'employees' => Employee::count(),
                'leave_types' => LeaveType::count(),
                'leaves' => Leave::count(),
                'letters' => Letter::count(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Table counts retrieved successfully',
                'data' => $counts,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve table counts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get count of a specific table
     */
    public function getTableCount(string $tableName): JsonResponse
    {
        try {
            $tableModels = [
                'users' => User::class,
                'provinces' => Province::class,
                'zonals' => Zonal::class,
                'regions' => Region::class,
                'branches' => Branch::class,
                'departments' => Department::class,
                'designations' => Designation::class,
                'employees' => Employee::class,
                'leave_types' => LeaveType::class,
                'leaves' => Leave::class,
                'letters' => Letter::class,
            ];

            if (!isset($tableModels[$tableName])) {
                return response()->json([
                    'success' => false,
                    'message' => "Table '{$tableName}' not found",
                    'available_tables' => array_keys($tableModels),
                ], 404);
            }

            $modelClass = $tableModels[$tableName];
            $count = $modelClass::count();

            return response()->json([
                'success' => true,
                'message' => "Count for {$tableName} retrieved successfully",
                'data' => [
                    'table' => $tableName,
                    'count' => $count,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Failed to retrieve count for table '{$tableName}'",
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get count of inactive employees (is_active = 0)
     */
    public function getInactiveEmployeeCount(): JsonResponse
    {
        try {
            $inactiveCount = Employee::where('is_active', 0)->count();
            $activeCount = Employee::where('is_active', 1)->count();
            $totalCount = Employee::count();

            return response()->json([
                'success' => true,
                'message' => 'Employee active/inactive counts retrieved successfully',
                'data' => [
                    'total' => $totalCount,
                    'active' => $activeCount,
                    'inactive' => $inactiveCount,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve employee counts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get count of employees grouped by employee type
     */
    public function getEmployeeTypeCounts(): JsonResponse
    {
        try {
            $typeCounts = Employee::selectRaw('employee_type, COUNT(*) as count')
                ->groupBy('employee_type')
                ->get()
                ->pluck('count', 'employee_type');

            return response()->json([
                'success' => true,
                'message' => 'Employee type counts retrieved successfully',
                'data' => $typeCounts,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve employee type counts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
