<?php

// app/Http/Controllers/V1/PayrollController.php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\PayrollRecord;
use App\Models\PayslipRequest;
use App\Models\Employee;
use App\Services\CdpConnectService;
use App\Services\LoanDeductionService;
use App\Services\PayrollActivationService;
use App\Services\SriLankanTaxService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class PayrollController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Payroll View', only: ['index']),
            new Middleware('permission:Payroll Request', only: ['requestPayslip']),
            new Middleware('permission:Payroll Print', only: ['printPayslip', 'downloadSigned']),
            new Middleware('permission:Payroll Metrics', only: ['getPayrollMetrics'])
        ];
    }

    public function index(Request $request)
    {
        try {
            $user = Auth::user();

            $query = PayrollRecord::with(['latestRequest'])
                ->where('user_id', $user->id)
                ->orderBy('month', 'desc');

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $payrollRecords = $query->get();

            // Employees can only see payroll for activated months
            if (! Auth::user()->can(PayrollActivationService::MANAGE_PERMISSION)) {
                $activeMonths = \App\Models\PayrollMonth::where('status', '!=', 'inactive')
                    ->pluck('month')
                    ->all();

                $payrollRecords = $payrollRecords->filter(function ($record) use ($activeMonths) {
                    $normalized = PayrollActivationService::normalizeMonth($record->month);
                    return $normalized !== null && in_array($normalized, $activeMonths, true);
                })->values();
            }

            // Get current month (first record)
            $currentMonth = $payrollRecords->first();

            // Calculate statutory contributions for current month
            $statutory = null;
            if ($currentMonth) {
                $statutory = [
                    'epf_employee' => $currentMonth->epf_employee,
                    'epf_employer' => $currentMonth->epf_employer,
                    'etf_employer' => $currentMonth->etf_employer,
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payroll records retrieved successfully',
                'records' => $payrollRecords,
                'current_month' => $currentMonth,
                'statutory' => $statutory,
            ]);

        } catch (\Throwable $th) {
            \Log::error('Failed to retrieve payroll records', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve payroll records',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function requestPayslip(Request $request, $payrollRecordId)
    {
        try {
            $request->validate([
                'reason' => 'nullable|string|max:500',
            ]);

            $payrollRecord = PayrollRecord::where('id', $payrollRecordId)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            // Check for existing requests
            $existingRequest = PayslipRequest::where('user_id', Auth::id())
                ->where('payroll_record_id', $payrollRecord->id)
                ->first();

            if ($existingRequest) {
                // If request exists and is pending or approved, don't allow new request
                if (in_array($existingRequest->status, ['pending', 'approved'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'A '.$existingRequest->status.' request already exists for this payslip',
                    ], 400);
                }

                // If request was rejected, update it to pending (re-request)
                if ($existingRequest->status === 'rejected') {
                    $existingRequest->update([
                        'status' => 'pending',
                        'reason' => $request->reason,
                        'rejection_reason' => null, // Clear the rejection reason
                        'updated_at' => now(),
                    ]);

                    \Log::info('Payslip request re-submitted', [
                        'user_id' => Auth::id(),
                        'payroll_record_id' => $payrollRecord->id,
                        'request_id' => $existingRequest->id,
                        'previous_status' => 'rejected',
                    ]);

                    return response()->json([
                        'status' => 'success',
                        'message' => 'Request re-submitted successfully',
                        'request' => $existingRequest,
                    ]);
                }
            }

            // Create new request if none exists
            $payslipRequest = PayslipRequest::create([
                'user_id' => Auth::id(),
                'payroll_record_id' => $payrollRecord->id,
                'status' => 'pending',
                'reason' => $request->reason,
            ]);

            \Log::info('Payslip request submitted', [
                'user_id' => Auth::id(),
                'payroll_record_id' => $payrollRecord->id,
                'request_id' => $payslipRequest->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Request submitted successfully',
                'request' => $payslipRequest,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to submit payslip request', [
                'user_id' => Auth::id(),
                'payroll_record_id' => $payrollRecordId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit request: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getRequestStatus($payrollRecordId)
    {
        try {
            $payrollRecord = PayrollRecord::where('id', $payrollRecordId)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $request = PayslipRequest::where('user_id', Auth::id())
                ->where('payroll_record_id', $payrollRecord->id)
                ->with('approver')
                ->first();

            return response()->json([
                'status' => 'success',
                'request' => $request,
                'can_print' => $request && $request->status === 'approved' && $request->signed_file_path,
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get request status',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Print/download signed payslip
     */
    public function printPayslip($payrollRecordId)
    {
        try {
            $user = Auth::user();

            $payrollRecord = PayrollRecord::where('id', $payrollRecordId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if approved request exists for this payslip
            $approvedRequest = PayslipRequest::where('user_id', $user->id)
                ->where('payroll_record_id', $payrollRecord->id)
                ->where('status', 'approved')
                ->whereNotNull('signed_file_path')
                ->first();

            if (! $approvedRequest) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No approved request found for this payslip',
                ], 403);
            }

            // Get the file path from the database
            $filePath = $approvedRequest->signed_file_path;

            $fullPath = public_path($filePath);
            if (File::exists($fullPath)) {
                $filename = "payslip_{$payrollRecord->month}_{$user->name}.pdf";
                $file = File::get($fullPath);

                return response($file, 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
            }

            // If file not found, log error
            \Log::error('Payslip file not found', [
                'file_path' => $filePath,
                'user_id' => $user->id,
                'request_id' => $approvedRequest->id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Payslip file not found. Please contact HR.',
            ], 404);

        } catch (\Exception $e) {
            \Log::error('Failed to print payslip', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to download payslip: '.$e->getMessage(),
            ], 500);
        }
    }

    public function downloadSigned($payslipRequest)
    {
        try {
            $user = Auth::user();

            $request = PayslipRequest::where('id', $payslipRequest)
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->whereNotNull('signed_file_path')
                ->first();

            if (! $request) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No approved signed payslip found',
                ], 404);
            }

            $filePath = $request->signed_file_path;

            $fullPath = public_path($filePath);
            if (File::exists($fullPath)) {
                $filename = "payslip_signed_{$user->name}.pdf";
                $file = File::get($fullPath);

                return response($file, 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
            }

            \Log::error('Signed payslip file not found', [
                'file_path' => $filePath,
                'user_id' => $user->id,
                'request_id' => $request->id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Signed payslip file not found on server',
            ], 404);

        } catch (\Exception $e) {
            \Log::error('Failed to download signed payslip', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to download signed payslip: '.$e->getMessage(),
            ], 500);
        }
    }

    public function requestPayslipByPeriod(Request $request)
    {
        try {
            $request->validate([
                'period' => 'required|string',
                'reason' => 'nullable|string|max:500',
            ]);

            $user = Auth::user();

            // Can only request a payslip for a month that has been activated
            if (! PayrollActivationService::canView($request->period, $user)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payroll for this month has not been activated yet.',
                ], 403);
            }

            $payslipRequest = PayslipRequest::create([
                'user_id' => $user->id,
                'employee_id' => $user->employee_id,
                'period' => $request->period,
                'status' => 'pending',
                'reason' => $request->reason,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Request submitted successfully',
                'request' => $payslipRequest,
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
                'message' => 'Failed to submit request: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getRequestStatusByPeriod(Request $request)
    {
        try {
            $period = $request->query('period');
            if (! $period) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Period query parameter is required',
                ], 400);
            }

            $user = Auth::user();
            $userId = $user->id;

            // Allow admins to look up another user's request (e.g. when viewing an employee's payroll details)
            $requestedUserId = $request->query('user_id');
            if ($requestedUserId) {
                $isAdmin = in_array($user->user_type, ['admin', 'development_admin'], true)
                    || $user->hasRole('Super Admin')
                    || $user->roles->pluck('name')->contains(fn ($roleName) => stripos($roleName, 'admin') !== false);

                if ($isAdmin) {
                    $userId = (int) $requestedUserId;
                }
            }

            $payslipRequest = PayslipRequest::where('user_id', $userId)
                ->where('period', $period)
                ->with('approver')
                ->first();

            return response()->json([
                'status' => 'success',
                'request' => $payslipRequest,
                'payroll_record_id' => $payslipRequest?->payroll_record_id,
                'can_print' => $payslipRequest && $payslipRequest->status === 'approved' && $payslipRequest->signed_file_path,
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get request status: '.$th->getMessage(),
            ], 500);
        }
    }

    public function getPayrollMetrics(Request $request, CdpConnectService $cdpService, $employeeId = null)
    {
        try {
            if (! $employeeId) {
                $user = Auth::user();
                $employeeId = $user->employee_id;
            }

            if (! $employeeId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee ID is required or user is not linked to an employee',
                ], 400);
            }

            $employee = Employee::with(['designation', 'department', 'activeSalaryDetail'])->find($employeeId);

            if (! $employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found',
                ], 404);
            }

            $designation = $employee->designation;
            $salaryOverride = $employee->activeSalaryDetail;

            // Use employee salary override if present, otherwise fall back to designation
            $getSalaryField = function ($field) use ($salaryOverride, $designation) {
                if ($salaryOverride && ! is_null($salaryOverride->{$field})) {
                    return (float) $salaryOverride->{$field};
                }
                return $designation ? (float) ($designation->{$field} ?? 0) : 0.0;
            };

            $totalPackage = $getSalaryField('total_package');

            // Fetch metrics from external API service
            $period = $request->get('period_key', now()->format('Y-m'));

            // Payroll for a month is only visible after HR activates it
            if (! PayrollActivationService::canView($period, Auth::user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payroll for this month has not been activated yet.',
                ], 403);
            }

            $achievement = null;
            $metricsNotFound = true;
            $commission = 0.0;
            $overrideCommission = 0.0;
            $totalCommission = 0.0;
            $targetAmount = 0.0;
            $achievementAmount = 0.0;
            $recoverAmount = 0.0;

            if ($employee->employee_code) {
                $cdpUser = $cdpService->fetchEmployeeMetrics($employee->employee_code, $period);

                \Log::info('CDP User Response', [
                    'employee_code' => $employee->employee_code,
                    'period' => $period,
                    'cdpUser' => $cdpUser,
                    'has_metrics' => isset($cdpUser['metrics']),
                    'metrics_value' => $cdpUser['metrics'] ?? null,
                ]);

                if ($cdpUser && isset($cdpUser['metrics'])) {
                    $metrics = $cdpUser['metrics'];

                    $commission = (float) ($metrics['commission'] ?? 0.0);
                    $overrideCommission = (float) ($metrics['override_commission'] ?? 0.0);
                    $totalCommission = (float) ($metrics['total_commission'] ?? 0.0);
                    $targetAmount = (float) ($metrics['target_amount'] ?? 0.0);
                    $achievementAmount = (float) ($metrics['achievement_amount'] ?? 0.0);
                    $recoverAmount = (float) ($metrics['recover_amount'] ?? 0.0);

                    // Try different possible keys for achievement / performance
                    $achievementVal = $metrics['achievement_percentage']
                        ?? $metrics['performance_percentage']
                        ?? $metrics['achievement']
                        ?? $metrics['performance']
                        ?? $metrics['score']
                        ?? null;

                    if ($achievementVal !== null) {
                        $achievement = (float) $achievementVal;
                        $metricsNotFound = false;
                    }
                }
            }

            // Allow request override for achievement (useful for manual calculation or testing)
            if ($request->has('achievement')) {
                $achievement = (float) $request->get('achievement');
                $metricsNotFound = false;
            }

            if ($achievement === null) {
                $achievement = 0.0;
            }

            // Boundary logic:
            // Below 50% -> No (0%), except permanent staff get basic_salary floor
            // 50% - 65% -> 50% Total Package
            // >65% - 90% -> 75% Total Package
            // Over 90% -> 100% Total Package + Mobile Payment
            $paymentPercentage = 0;
            $paymentCriteria = 'No';
            $isPermanent = ($employee->employee_type === 'permanent');

            if ($achievement < 50) {
                $paymentPercentage = 0;
                $paymentCriteria = $isPermanent ? 'Basic Salary (Guaranteed)' : 'No';
            } elseif ($achievement >= 50 && $achievement <= 65) {
                $paymentPercentage = 50;
                $paymentCriteria = '50% Total Package';
            } elseif ($achievement > 65 && $achievement <= 90) {
                $paymentPercentage = 75;
                $paymentCriteria = '75% Total Package';
            } else {
                $paymentPercentage = 100;
                $paymentCriteria = '100% Package + Mobile Bonus';
            }

            $calculatedPayment = ($paymentPercentage / 100) * $totalPackage;

            // Permanent staff always receive basic salary floor even when <50% achievement
            $basicSalary = $getSalaryField('basic_salary');
            if ($achievement < 50 && $isPermanent) {
                $calculatedPayment = $basicSalary;
            }

            // For >90% achievement, add mobile_payment as bonus on top of full package
            $mobilePaymentBonus = 0.0;
            if ($achievement > 90) {
                $calculatedPayment = $totalPackage;
                $mobilePaymentBonus = $getSalaryField('mobile_payment');
            }

            $monthlyTarget = $getSalaryField('monthly_target');
            $travelReimbursement = $getSalaryField('travel_reimbursement');
            $vehicleAllowance = $getSalaryField('vehicle_rental');
            $performanceAllowance = $getSalaryField('performance_allowance');
            $incentive = $getSalaryField('incentive');
            $positionAllowance = $getSalaryField('position_allowance');
            $mobilePayment = $getSalaryField('mobile_payment');
            $howMuchPaid = $calculatedPayment + $mobilePaymentBonus + $totalCommission;

            // Deductions: EPF (8% of basic) + PAYE tax (on howMuchPaid) apply only to permanent staff.
            // CDP recover amount applies to everyone.
            $epfEmployee = 0.0;
            $incomeTax = 0.0;
            if ($isPermanent) {
                $epfEmployee = SriLankanTaxService::epfEmployee($basicSalary);
                $incomeTax = SriLankanTaxService::paye($howMuchPaid);
                \Log::info('Deductions', [
                    'epfEmployee' => $epfEmployee,
                    'howMuchPaid' => $howMuchPaid,
                    'incomeTax' => $incomeTax,
                ]);
            }

            // ── Active Loan / Advance / Custom Deductions ──────────────────────
            // Sum monthly installments from the loans table that apply to this period
            $loanDeductions = LoanDeductionService::activeForPeriod($employee->id, $period);
            $loanDeductionItems  = $loanDeductions['items'];
            $loanDeductionsTotal = $loanDeductions['total'];

            $totalDeductions = round($epfEmployee + $incomeTax + $recoverAmount + $loanDeductionsTotal, 2);

            // WHT (5% for non-permanent sales staff when howMuchPaid > 100,000)
            $whtTax = 0.0;
            $deptForWht = $employee->department;
            $isSalesStaff = $deptForWht && strtolower($deptForWht->name) === 'sales';
            if (!$isPermanent && $isSalesStaff && $howMuchPaid > 100000) {
                $whtTax = round($howMuchPaid * 0.05, 2);
            }
            $totalDeductions = round($totalDeductions + $whtTax, 2);
            $netPay = round($howMuchPaid - $totalDeductions, 2);

            // ── Stored 3-payment records (5th/15th/20th) ─────────────────────
            // When present, they are the source of truth for display.
            $linkedUserId = $employee->user()?->value('id');
            $payrollRecords = \App\Models\PayrollRecord::where('user_id', $linkedUserId)
                ->where('month', $period)
                ->orderBy('pay_day')
                ->get()
                ->map(function ($rec) {
                    $rec->makeHidden(['file_path']);
                    return $rec;
                });

            if ($payrollRecords->isNotEmpty()) {
                $howMuchPaid   = round($payrollRecords->sum('how_much_paid'), 2);
                $epfEmployee   = round($payrollRecords->sum('epf_employee'), 2);
                $incomeTax     = round($payrollRecords->sum('paye_tax'), 2);
                $whtTax        = round($payrollRecords->sum('wht_tax'), 2);
                $apiitTax      = round($payrollRecords->sum('apiit_tax'), 2);
                $stampFee      = round($payrollRecords->sum('stamp_fee'), 2);
                $recoverAmount = round($payrollRecords->sum('recover_amount'), 2);
                $totalDeductions = round($payrollRecords->sum('total_deductions'), 2);
                $netPay        = round($payrollRecords->sum('net'), 2);
            } else {
                $apiitTax = 0.0;
                $stampFee = 0.0;
                $payrollRecords = collect();
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'employee_id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'full_name' => $employee->full_name,
                    'employee_type' => $employee->employee_type,
                    'designation_id' => $employee->designation_id,
                    'designation_name' => $designation ? $designation->name : null,
                    'basic_salary' => $basicSalary,
                    'vehicle_allowance' => $vehicleAllowance,
                    'performance_allowance' => $performanceAllowance,
                    'travel_reimbursement' => $travelReimbursement,
                    'monthly_target' => $monthlyTarget,
                    'incentive' => $incentive,
                    'position_allowance' => $positionAllowance,
                    'mobile_payment' => $mobilePayment,
                    'mobile_payment_bonus' => $mobilePaymentBonus,
                    'total_package' => $totalPackage,
                    'achievement_percentage' => $achievement,
                    'payment_percentage' => $paymentPercentage,
                    'payment_criteria' => $paymentCriteria,
                    'calculated_payment' => $calculatedPayment,
                    'how_much_paid' => $howMuchPaid,
                    'commission' => $commission,
                    'override_commission' => $overrideCommission,
                    'total_commission' => $totalCommission,
                    'target_amount' => $targetAmount,
                    'achievement_amount' => $achievementAmount,
                    'recover_amount' => $recoverAmount,
                    'epf' => $epfEmployee,
                    'income_tax' => $incomeTax,
                    'wht_tax' => $whtTax,
                    'apiit_tax' => $apiitTax,
                    'stamp_fee' => $stampFee,
                    'loan_deductions' => $loanDeductionItems,
                    'loan_deductions_total' => round($loanDeductionsTotal, 2),
                    'total_deductions' => $totalDeductions,
                    'net_pay' => $netPay,
                    'period' => $period,
                    'metrics_found' => ! $metricsNotFound,
                    'payroll_records' => $payrollRecords->values(),
                ],
            ]);

        } catch (\Throwable $th) {
            \Log::error('Failed to retrieve payroll metrics', [
                'employee_id' => $employeeId,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve payroll metrics',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
