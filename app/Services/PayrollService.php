<?php

namespace App\Services;

use App\Models\User;
use App\Models\PayrollRecord;
use App\Models\PayrollDeduction;
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
            
            $gross = $basic + $allowances;
            $epfEmployee = $gross * 0.08; // 8%
            $epfEmployer = $gross * 0.12; // 12%
            $etfEmployer = $gross * 0.03; // 3%

            // Upsert the payroll record first so we have its ID for deductions
            $record = PayrollRecord::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'month'   => $month,
                ],
                [
                    'basic'        => $basic,
                    'allowances'   => $allowances,
                    'deductions'   => 0,
                    'net'          => $gross - $epfEmployee,
                    'epf_employee' => $epfEmployee,
                    'epf_employer' => $epfEmployer,
                    'etf_employer' => $etfEmployer,
                    'status'       => 'draft',
                    'processed_at' => null,
                ]
            );

            // Auto-populate deductions from loans/advances that apply to this period
            $loanDeductionTotal = $this->applyActiveDeductions($user, $record->id, $month);

            // Recalculate net with all deductions applied
            $record->deductions = $loanDeductionTotal;
            $record->net        = $gross - $epfEmployee - $loanDeductionTotal;
            $record->save();

            $generated[] = $record;
        }
        
        return $generated;
    }

    /**
     * Auto-populate PayrollDeduction records from Loans that apply to the given period.
     * Returns the total auto-deduction amount.
     */
    private function applyActiveDeductions($user, int $payrollRecordId, string $month): float
    {
        // Remove any previously auto-generated deductions for this payroll record
        PayrollDeduction::where('payroll_record_id', $payrollRecordId)
            ->where('is_auto', true)
            ->delete();

        $total = 0.0;

        // Find the employee linked to this user
        $employeeId = $user->employee_id ?? null;
        if (!$employeeId) {
            return $total;
        }

        // Only loans/advances that are due in this payroll period
        $loanDeductions = LoanDeductionService::activeForPeriod((int) $employeeId, $month);

        foreach ($loanDeductions['items'] as $item) {
            PayrollDeduction::create([
                'payroll_record_id' => $payrollRecordId,
                'type'              => $item['type'],
                'label'             => $item['label'],
                'amount'            => $item['amount'],
                'loan_id'           => $item['loan_id'],
                'is_auto'           => true,
            ]);

            $total += $item['amount'];
        }

        return $total;
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
}