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
            // Get or calculate salary components
            $basic = $user->basic_salary ?? 0;
            $allowances = $this->calculateAllowances($user);
            $deductions = $this->calculateDeductions($user);
            
            $gross = $basic + $allowances;
            $epfEmployee = $gross * 0.08; // 8%
            $epfEmployer = $gross * 0.12; // 12%
            $etfEmployer = $gross * 0.03; // 3%
            $net = $gross - $epfEmployee - $deductions;
            
            $record = PayrollRecord::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'month' => $month
                ],
                [
                    'basic' => $basic,
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