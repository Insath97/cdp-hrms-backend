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
            $employee = Employee::with('designation')->findOrFail($employeeId);
            $designation = $employee->designation;
            $salaryDetail = EmployeeSalaryDetail::where('employee_id', $employeeId)
                ->whereNull('effective_to')
                ->latest('effective_from')
                ->first();

            $componentFields = [
                'basic_salary', 'travel_reimbursement', 'vehicle_rental',
                'performance_allowance', 'incentive', 'position_allowance',
                'mobile_payment', 'monthly_target',
            ];

            $data = [];
            $hasOverride = false;

            // Use employee override value if present, otherwise fall back to designation
            foreach ($componentFields as $field) {
                if ($salaryDetail && ! is_null($salaryDetail->{$field})) {
                    $data[$field] = (float) $salaryDetail->{$field};
                    $hasOverride = true;
                } else {
                    $data[$field] = $designation ? (float) ($designation->{$field} ?? 0) : 0;
                }
            }

            if ($salaryDetail && ! is_null($salaryDetail->total_package)) {
                $data['total_package'] = (float) $salaryDetail->total_package;
            } elseif ($designation && ! is_null($designation->total_package)) {
                $data['total_package'] = (float) $designation->total_package;
            } else {
                $data['total_package'] = (float) ($data['basic_salary'] + $data['travel_reimbursement'] + $data['vehicle_rental'] + $data['performance_allowance'] + $data['incentive'] + $data['position_allowance']);
            }

            $data['source'] = $hasOverride ? 'employee' : 'designation';
            $data['designation_id'] = $designation?->id;
            $data['designation_name'] = $designation?->name;
            $data['effective_from'] = $salaryDetail?->effective_from?->format('Y-m-d');
            $data['effective_to'] = $salaryDetail?->effective_to?->format('Y-m-d');
            $data['salary_designation_id'] = $salaryDetail?->designation_id;
            $data['salary_designation_name'] = $salaryDetail?->designation_name;

            return response()->json([
                'status' => 'success',
                'data' => $data,
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
                'effective_from' => 'nullable|date',
            ]);

            $employee = Employee::findOrFail($employeeId);

            $data = $request->only([
                'basic_salary', 'travel_reimbursement', 'vehicle_rental',
                'performance_allowance', 'incentive', 'position_allowance',
                'mobile_payment', 'monthly_target', 'effective_from',
            ]);

            $today = \Carbon\Carbon::today()->toDateString();

            $effectiveFrom = $data['effective_from'] ?? $today;
            unset($data['effective_from']);

            $currentDesignation = \App\Models\Designation::find($employee->designation_id);

            // Close the currently active salary record
            EmployeeSalaryDetail::where('employee_id', $employeeId)
                ->whereNull('effective_to')
                ->update(['effective_to' => $today]);

            // Create new salary record
            $salaryDetail = EmployeeSalaryDetail::create(array_merge(
                $data,
                [
                    'employee_id' => $employeeId,
                    'designation_id' => $currentDesignation?->id,
                    'designation_name' => $currentDesignation?->name,
                    'effective_from' => $effectiveFrom,
                ]
            ));

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
            $salaryDetail = EmployeeSalaryDetail::where('employee_id', $employeeId)
                ->whereNull('effective_to')
                ->first();

            if (!$salaryDetail) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active salary details found for this employee',
                ], 404);
            }

            $salaryDetail->update(['effective_to' => now()->subDay()->toDateString()]);

            $this->logActivity('DELETE', 'Employee Salary', "Archived salary override for employee ID: {$employeeId}");

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

    public function history($employeeId)
    {
        try {
            $employee = Employee::findOrFail($employeeId);
            $history = EmployeeSalaryDetail::where('employee_id', $employeeId)
                ->orderByDesc('effective_from')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $history,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve salary history: ' . $e->getMessage(),
            ], 500);
        }
    }
}
