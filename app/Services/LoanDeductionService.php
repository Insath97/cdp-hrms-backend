<?php

namespace App\Services;

use App\Models\Loan;
use Illuminate\Support\Carbon;

class LoanDeductionService
{
    /**
     * Return the loan/advance installments that apply for a given payroll period.
     *
     * A loan is only deducted when:
     *  - the payroll period falls within the loan's effective month window
     *    [effective_from, effective_to] (YYYY-MM granularity), and
     *  - the number of installments already scheduled (months elapsed since the
     *    effective from month) is still below the total number of installments
     *    for the loan.
     *
     * @return array{items: array<int, array{loan_id: int, type: string, label: string, amount: float}>, total: float}
     */
    public static function activeForPeriod(int $employeeId, string $period): array
    {
        $periodDate = self::parsePeriod($period);
        $periodYm = $periodDate->format('Y-m');

        $activeLoans = Loan::where('employee_id', $employeeId)
            ->where('status', 'active')
            ->where('approval_status', 'approved')
            ->where('remaining_amount', '>', 0)
            ->get();

        $items = [];
        $total = 0.0;

        foreach ($activeLoans as $loan) {
            // Effective month window (YYYY-MM strings) with legacy date fallback
            $effectiveFrom = $loan->effective_from ?: ($loan->start_date ? $loan->start_date->format('Y-m') : null);
            $effectiveTo = $loan->effective_to ?: ($loan->end_date ? $loan->end_date->format('Y-m') : null);
            $start = $effectiveFrom ? Carbon::createFromFormat('Y-m', $effectiveFrom)->startOfMonth() : null;

            // Not yet effective -> skip
            if ($effectiveFrom && $periodYm < $effectiveFrom) {
                continue;
            }

            // Past the effective end month -> no longer deducted
            if ($effectiveTo && $periodYm > $effectiveTo) {
                continue;
            }

            $monthlyInstallment = (float) $loan->monthly_installment;
            if ($monthlyInstallment <= 0) {
                continue;
            }

            // Total number of installments the loan is meant to be paid over
            $totalInstallments = max(1, (int) ceil((float) $loan->total_amount / $monthlyInstallment));

            // Months elapsed since the loan became effective (start month = 0)
            $elapsed = $start
                ? (($periodDate->year - $start->year) * 12) + ($periodDate->month - $start->month)
                : 0;

            // All scheduled installments already deducted -> stop
            if ($elapsed >= $totalInstallments) {
                continue;
            }

            $installment = min($monthlyInstallment, (float) $loan->remaining_amount);
            if ($installment <= 0) {
                continue;
            }

            $typeMap = [
                'Salary Advance'     => 'advance',
                'Policy Cancellation'=> 'other',
                'Loan'               => 'loan',
                'Tax / VAT'          => 'tax',
                'Damage Penalty'     => 'penalty',
            ];

            $items[] = [
                'loan_id' => $loan->id,
                'type'    => $typeMap[$loan->loan_type] ?? 'other',
                'label'   => $loan->loan_type . ' Installment',
                'amount'  => round($installment, 2),
            ];

            $total += $installment;
        }

        return [
            'items' => $items,
            'total' => round($total, 2),
        ];
    }

    /**
     * Parse a payroll period (e.g. "2026-08" or "August 2026") into a Carbon date.
     */
    private static function parsePeriod(string $period): Carbon
    {
        $period = trim($period);

        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            return Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        }

        return Carbon::parse($period)->startOfMonth();
    }
}
