<?php

namespace App\Services;

use App\Models\User;
use App\Models\PayrollRecord;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function generateMonthlyPayroll(string $month, array $userIds = null)
    {
        $users = $userIds ? User::whereIn('id', $userIds)->get() : User::all();
        $generated = [];
        
        foreach ($users as $user) {
            $user->load('employee.designation');
            $employee = $user->employee;
            if (!$employee) {
                continue; // Skip users without an employee record
            }
            
            $designation = $employee->designation;
            $totalPackage = $designation ? (float)($designation->total_package ?? 0) : 0.0;
            $basic = $designation ? (float)($designation->basic_salary ?? 0) : 0.0;
            
            // Fetch CDP metrics
            $cdpService = app(\App\Services\CdpConnectService::class);
            $achievement = 0.0;
            
            if ($employee->employee_code) {
                $cdpUser = $cdpService->fetchEmployeeMetrics($employee->employee_code, $month);
                if ($cdpUser && isset($cdpUser['metrics'])) {
                    $metrics = $cdpUser['metrics'];
                    $achievementVal = $metrics['achievement_percentage']
                        ?? $metrics['performance_percentage']
                        ?? $metrics['achievement']
                        ?? $metrics['performance']
                        ?? $metrics['score']
                        ?? null;
                        
                    if ($achievementVal !== null) {
                        $achievement = (float) $achievementVal;
                    }
                }
            }
            
            if ($achievement < 50) {
                $paymentPercentage = 0;
            } elseif ($achievement >= 50 && $achievement < 65) {
                $paymentPercentage = 50;
            } elseif ($achievement >= 65 && $achievement < 90) {
                $paymentPercentage = 75;
            } else {
                $paymentPercentage = 100;
            }
            
            $calculatedPayment = ($paymentPercentage / 100) * $totalPackage;
            
            $allowances = max(0, $calculatedPayment - $basic);
            $actualBasic = min($basic, $calculatedPayment);
            $deductions = $this->calculateDeductions($user);
            
            $gross = $calculatedPayment;
            $epfEmployee = $actualBasic * 0.08;
            $epfEmployer = $actualBasic * 0.12;
            $etfEmployer = $actualBasic * 0.03;
            $net = $gross - $epfEmployee - $deductions;
            
            $record = PayrollRecord::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'month' => $month
                ],
                [
                    'basic' => $actualBasic,
                    'allowances' => $allowances,
                    'deductions' => $deductions,
                    'net' => $net,
                    'epf_employee' => $epfEmployee,
                    'epf_employer' => $epfEmployer,
                    'etf_employer' => $etfEmployer,
                    'status' => 'draft',
                    'processed_at' => null
                ]
            );
            
            $generated[] = $record;
        }
        
        return $generated;
    }
    
    private function calculateAllowances($user)
    {
        // Implement your allowance calculation logic
        $allowances = 0;
        
        // Example: Transport allowance
        if ($user->transport_allowance ?? false) {
            $allowances += 5000;
        }
        
        // Example: Meal allowance
        if ($user->meal_allowance ?? false) {
            $allowances += 3000;
        }
        
        return $allowances;
    }
    
    private function calculateDeductions($user)
    {
        // Implement your deduction calculation logic
        // Example: Loan deductions, advance deductions, etc.
        return 0;
    }
}