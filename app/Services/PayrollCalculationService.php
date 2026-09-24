<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollRecord;
use App\Models\PayrollDeduction;
use App\Services\CdpConnectService;
use App\Services\LoanDeductionService;
use App\Services\PayrollSettingsService;
use App\Services\SriLankanTaxService;
use Carbon\Carbon;

class PayrollCalculationService
{
    public const PAY_BASIC = 'basic';        // 5th
    public const PAY_COMMISSION = 'commission'; // 15th
    public const PAY_ALLOWANCE = 'allowance';   // 20th

    /**
     * Achievement -> payment percentage matrix used for the
     * "depends on achievement" components.
     */
    public static function paymentPercentage(float $achievement): float
    {
        if ($achievement < 50) {
            return 0;
        }
        if ($achievement <= 65) {
            return 50;
        }
        if ($achievement <= 90) {
            return 75;
        }
        return 100;
    }

    /**
     * Build the full 5th/15th/20th breakdown for one employee + month.
     * Returns a list of PayrollRecord[] (created/updated in the DB).
     */
    public function processEmployee(string $period, Employee $employee, array $manualDeductionTypes = ['unauthorized', 'late', 'loan', 'other']): array
    {
        $designation = $employee->designation;
        $salaryOverride = $employee->salaryDetail()
            ->activeForPeriod(Carbon::parse($period))->first();

        $getSalaryField = function ($field) use ($salaryOverride, $designation) {
            if ($salaryOverride && ! is_null($salaryOverride->{$field})) {
                return (float) $salaryOverride->{$field};
            }
            return $designation ? (float) ($designation->{$field} ?? 0) : 0.0;
        };

        $basicSalary          = $getSalaryField('basic_salary');
        $fuel                 = $getSalaryField('travel_reimbursement');  // fuel
        $vehicleAllowance     = $getSalaryField('vehicle_rental');        // vehicle allowance
        $incentive            = $getSalaryField('incentive');
        $performanceAllowance = $getSalaryField('performance_allowance');
        $positionAllowance    = $getSalaryField('position_allowance');
        $mobilePayment        = $getSalaryField('mobile_payment');
        $totalPackage         = $getSalaryField('total_package');
        $monthlyTarget        = $getSalaryField('monthly_target');

        $isPermanent = ($employee->employee_type === 'permanent');
        $department = $employee->department;
        $isSales = $department && strtolower($department->name) === 'sales';

        // CDP metrics
        $achievement = 0.0;
        $commission = 0.0;
        $overrideCommission = 0.0;
        $recoverAmount = 0.0;
        if ($employee->employee_code) {
            $cdpUser = app(CdpConnectService::class)->fetchEmployeeMetrics($employee->employee_code, $period);
            if ($cdpUser && isset($cdpUser['metrics'])) {
                $m = $cdpUser['metrics'];
                $achievement = (float) ($m['achievement_percentage'] ?? $m['performance_percentage'] ?? $m['achievement'] ?? $m['performance'] ?? $m['score'] ?? 0);
                $commission = (float) ($m['commission'] ?? 0);
                $overrideCommission = (float) ($m['override_commission'] ?? 0);
                $recoverAmount = (float) ($m['recover_amount'] ?? 0);
            }
        }

        $paymentPct = self::paymentPercentage($achievement);
        $scaled = fn (float $v) => round($v * $paymentPct / 100, 2);

        $user = $employee->user;
        $common = [
            'user_id'            => $user?->id,
            'employee_id'        => $employee->id,
            'designation_id'     => $designation?->id,
            'designation_name'   => $designation?->name,
            'month'              => $period,
            'total_package'      => $totalPackage,
            'monthly_target'     => $monthlyTarget,
            'achievement_percentage' => $achievement,
            'payment_percentage' => $paymentPct,
            'status'             => 'draft',
            'processed_at'       => null,
        ];

        $stampFeeAmount = PayrollSettingsService::stampFeeAmount();
        $stampFeeThreshold = PayrollSettingsService::stampFeeThreshold();

        // Recover amount (CDP) applies to everyone; pool it via the cascade.
        $recoverPool = $recoverAmount;

        // ── 5th: Basic (permanent only) ────────────────────────────────────
        $basicRecord = null;
        if ($isPermanent) {
            $epfEmployee = SriLankanTaxService::epfEmployee($basicSalary);
            $epfEmployer = round($basicSalary * 0.12, 2);
            $etfEmployer = round($basicSalary * 0.03, 2);

            $stamp = 0.0;
            if (! $isSales && ($basicSalary + $fuel + $vehicleAllowance) > $stampFeeThreshold) {
                $stamp = $stampFeeAmount;
            }

            $howMuchPaid = $basicSalary;

            if ($howMuchPaid > 0) {
                $basicRecord = $this->upsertRecord(array_merge($common, [
                    'pay_day'               => self::PAY_BASIC,
                    'basic'                 => $basicSalary,
                    'how_much_paid'         => $howMuchPaid,
                    'epf_employee'          => $epfEmployee,
                    'epf_employer'          => $epfEmployer,
                    'etf_employer'          => $etfEmployer,
                    'stamp_fee'             => $stamp,
                    'payment_criteria'      => 'Basic Salary',
                ]));
            }
        }

        // ── 15th: Commission (sales staff only) ────────────────────────────
        $commissionRecord = null;
        if ($isSales) {
            $scaledIncentive = $scaled($incentive);
            $scaledPerformance = $scaled($performanceAllowance);
            $scaledPosition = $scaled($positionAllowance);

            $gross = round($commission + $overrideCommission + $scaledIncentive + $scaledPerformance + $scaledPosition, 2);

            // WHT (non-permanent sales only)
            $whtTax = 0.0;
            if (! $isPermanent) {
                $whtBase = round($commission + $overrideCommission + $scaled($totalPackage), 2);
                if ($whtBase > PayrollSettingsService::whtThreshold()) {
                    $whtTax = round($whtBase * PayrollSettingsService::whtRate() / 100, 2);
                }
            }

            // Mobile payment: only if enabled in settings AND achievement > 90%
            $mobileBonus = 0.0;
            if (PayrollSettingsService::mobilePaymentApplicable() && $achievement > 90) {
                $mobileBonus = $mobilePayment;
                $gross = round($gross + $mobileBonus, 2);
            }

            $howMuchPaid = $gross;

            $commissionRecord = null;
            if ($howMuchPaid > 0) {
                $commissionRecord = $this->upsertRecord(array_merge($common, [
                    'pay_day'               => self::PAY_COMMISSION,
                    'incentive'             => $scaledIncentive,
                    'performance_allowance' => $scaledPerformance,
                    'position_allowance'    => $scaledPosition,
                    'mobile_payment'        => $mobileBonus,
                    'mobile_payment_bonus'  => $mobileBonus,
                    'commission'            => $commission,
                    'override_commission'   => $overrideCommission,
                    'total_commission'      => round($commission + $overrideCommission, 2),
                    'how_much_paid'         => $howMuchPaid,
                    'wht_tax'               => $whtTax,
                    'stamp_fee'             => $stampFeeAmount,
                    'payment_criteria'      => 'Commission Payment',
                ]));
            }
        }

        // ── 20th: Allowance (everyone) ─────────────────────────────────────
        $scaledFuel = $scaled($fuel);
        $scaledVehicle = $scaled($vehicleAllowance);

        $allowanceRecord = null;
        if ($isPermanent) {
            // fuel + vehicle allowance - APIIT
            $apiitTax = SriLankanTaxService::apiit($scaled($totalPackage));
            $howMuchPaid = round($scaledFuel + $scaledVehicle, 2);

            if ($howMuchPaid > 0 || $apiitTax > 0) {
                $allowanceRecord = $this->upsertRecord(array_merge($common, [
                    'pay_day'               => self::PAY_ALLOWANCE,
                    'basic'                 => 0,
                    'travel_reimbursement'  => $scaledFuel,
                    'vehicle_rental'        => $scaledVehicle,
                    'how_much_paid'         => $howMuchPaid,
                    'apiit_tax'             => $apiitTax,
                    'payment_criteria'      => 'Allowance Payment',
                ]));
            }
        } else {
            // basic + fuel + vehicle allowance (no EPF, no APIIT)
            $howMuchPaid = round($basicSalary + $scaledFuel + $scaledVehicle, 2);

            if ($howMuchPaid > 0) {
                $allowanceRecord = $this->upsertRecord(array_merge($common, [
                    'pay_day'               => self::PAY_ALLOWANCE,
                    'basic'                 => $basicSalary,
                    'travel_reimbursement'  => $scaledFuel,
                    'vehicle_rental'        => $scaledVehicle,
                    'how_much_paid'         => $howMuchPaid,
                    'payment_criteria'      => 'Allowance Payment',
                ]));
            }
        }

        // ── Apply manual deduction cascade: 5th -> 15th -> 20th ────────────
        $records = array_values(array_filter([$basicRecord, $commissionRecord, $allowanceRecord]));

        // Remove any stale auto deduction rows (new architecture stores
        // statutory amounts in columns; loans/manual are pooled in cascade).
        PayrollDeduction::whereIn('payroll_record_id', array_column($records, 'id'))
            ->where('is_auto', true)
            ->delete();

        $this->applyManualDeductions($records, $employee, $period, $manualDeductionTypes, $recoverPool);

        // Clean up any stale records for this employee+month not belonging
        // to the new pay_days set.
        if ($user) {
            $keptPayDays = array_map(fn ($r) => $r->pay_day, $records);
            PayrollRecord::where('user_id', $user->id)
                ->where('month', $period)
                ->whereNotIn('pay_day', $keptPayDays)
                ->delete();
        }

        return $records;
    }

    /**
     * Cascade manual deductions (unauthorized/late/loan/other + recover)
     * across the 5th → 15th → 20th payments. Never makes net negative.
     */
    private function applyManualDeductions(array $records, Employee $employee, string $period, array $types, float $recoverPool = 0.0): void
    {
        // Auto loans/advances from active loans
        $loans = LoanDeductionService::activeForPeriod($employee->id, $period);
        $loanTotal = (float) $loans['total'];

        // Manual deductions already attached to this month's records
        $ids = collect($records)->pluck('id')->all();
        $manualTotal = PayrollDeduction::whereIn('payroll_record_id', $ids)
            ->whereIn('type', $types)
            ->where('approval_status', 'approved')
            ->sum('amount');

        // Distribute over each payment's available (gross - statutory).
        // Recover (CDP) is absorbed first, then loans + manual deductions.
        $pools = [
            'recover_amount' => round($recoverPool, 2),
            'loan_deductions' => round($loanTotal + (float) $manualTotal, 2),
        ];

        foreach ($records as $r) {
            $statutory = round(
                (float) $r->epf_employee
                + (float) $r->paye_tax
                + (float) $r->wht_tax
                + (float) $r->apiit_tax
                + (float) $r->stamp_fee,
                2
            );

            $appliedByType = [];
            foreach ($pools as $field => $amount) {
                if ($amount <= 0) {
                    $appliedByType[$field] = 0;
                    continue;
                }
                $available = round((float) $r->how_much_paid - $statutory - array_sum($appliedByType), 2);
                $applied = $available > 0 ? min($available, $amount) : 0;
                $pools[$field] = round($pools[$field] - $applied, 2);
                $appliedByType[$field] = round($applied, 2);
            }
            $appliedByType['recover_amount'] ??= 0;
            $appliedByType['loan_deductions'] ??= 0;

            $totalApplied = round($appliedByType['recover_amount'] + $appliedByType['loan_deductions'], 2);

            $r->recover_amount    = $appliedByType['recover_amount'];
            $r->loan_deductions   = $appliedByType['loan_deductions'];
            $r->total_deductions  = round($statutory + $totalApplied, 2);
            $r->net               = round((float) $r->how_much_paid - (float) $r->total_deductions, 2);
            $r->save();
        }
    }

    private function upsertRecord(array $data): PayrollRecord
    {
        return PayrollRecord::updateOrCreate(
            [
                'user_id' => $data['user_id'],
                'month'   => $data['month'],
                'pay_day' => $data['pay_day'],
            ],
            $data
        );
    }
}