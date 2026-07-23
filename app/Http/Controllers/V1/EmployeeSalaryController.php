<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeSalaryDetail;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Traits\ActivityLogTrait;

class EmployeeSalaryController extends Controller
{
    use ActivityLogTrait;

    public function show($employeeId)
    {
        try {
            $salaryDetail = EmployeeSalaryDetail::where('employee_id', $employeeId)->first();

            return response()->json([
                'status' => 'success',
                'data' => $salaryDetail,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve salary details: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function upsert(Request $request, $employeeId)
    {
        try {
            $request->validate([
                'basic_salary' => 'nullable|numeric|min:0',
                'travel_reimbursement' => 'nullable|numeric|min:0',
                'vehicle_rental' => 'nullable|numeric|min:0',
                'performance_allowance' => 'nullable|numeric|min:0',
                'incentive' => 'nullable|numeric|min:0',
                'position_allowance' => 'nullable|numeric|min:0',
                'mobile_payment' => 'nullable|numeric|min:0',
                'monthly_target' => 'nullable|numeric|min:0',
            ]);

            $employee = Employee::findOrFail($employeeId);

            $data = $request->only([
                'basic_salary', 'travel_reimbursement', 'vehicle_rental',
                'performance_allowance', 'incentive', 'position_allowance',
                'mobile_payment', 'monthly_target',
            ]);

            $salaryDetail = EmployeeSalaryDetail::updateOrCreate(
                ['employee_id' => $employeeId],
                $data
            );

            $this->logActivity('UPDATE', 'Employee Salary', "Updated salary details for employee ID: {$employeeId}");

            return response()->json([
                'status' => 'success',
                'message' => 'Salary details saved successfully',
                'data' => $salaryDetail,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to save salary details: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($employeeId)
    {
        try {
            $salaryDetail = EmployeeSalaryDetail::where('employee_id', $employeeId)->first();

            if (!$salaryDetail) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No salary details found for this employee',
                ], 404);
            }

            $salaryDetail->delete();

            $this->logActivity('DELETE', 'Employee Salary', "Removed salary override for employee ID: {$employeeId}");

            return response()->json([
                'status' => 'success',
                'message' => 'Salary override removed (will use designation defaults)',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete salary details: ' . $e->getMessage(),
            ], 500);
        }
    }
}
