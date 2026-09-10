<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\PayrollRecord;
use App\Models\PayrollMonth;
use App\Models\PayslipRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\CdpConnectService;
use App\Services\LoanDeductionService;
use App\Services\PayrollActivationService;
use App\Services\SriLankanTaxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Traits\ActivityLogTrait;

class PayrollAdminController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;
    /**
     * Get the middleware that should be assigned to the controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
            new Middleware('permission:Payroll Approve|Payroll View All', only: ['pendingRequests', 'allRequests', 'approveRequest']),
            new Middleware('permission:Payroll Reject', only: ['rejectRequest']),
            new Middleware('permission:Payroll Generate', only: ['bulkGenerate']),
            new Middleware('permission:Payroll Update', only: ['updatePayroll']),
            new Middleware('permission:Payroll Process', only: ['processPayroll']),
            new Middleware('permission:Payroll Activate|Payroll View All', only: ['getMonthStatus']),
            new Middleware('permission:Payroll Activate', only: ['activateMonth', 'lockMonth']),
        ];
    }

    /**
     * Get all pending payslip requests
     */
    public function pendingRequests()
    {
        try {
            $requests = PayslipRequest::with(['user', 'employee.designation', 'employee.department', 'payrollRecord'])
                ->where('status', 'pending')
                ->orderBy('created_at', 'asc')
                ->get();

            $this->attachMetricsToRequests($requests);

            \Log::info('Pending payroll requests viewed', [
                'user_id' => Auth::id(),
                'count' => $requests->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Pending requests retrieved successfully',
                'data' => $requests
            ]);

        } catch (\Throwable $th) {
            \Log::error('Failed to retrieve pending requests', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve pending requests: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get all payslip requests (pending, approved, rejected) with optional status filter.
     */
    public function allRequests(Request $request)
    {
        try {
            $query = PayslipRequest::with(['user', 'employee.designation', 'employee.department', 'payrollRecord', 'approver']);

            $status = $request->get('status');
            if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
                $query->where('status', $status);
            }

            $requests = $query->orderBy('created_at', 'desc')->get();

            $this->attachMetricsToRequests($requests);

            \Log::info('All payroll requests viewed', [
                'user_id' => Auth::id(),
                'count' => $requests->count(),
                'status' => $status,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Requests retrieved successfully',
                'data' => $requests
            ]);

        } catch (\Throwable $th) {
            \Log::error('Failed to retrieve payroll requests', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve payroll requests: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Attach computed payroll metrics to each payslip request.
     */
    private function attachMetricsToRequests($requests)
    {
        $cdpService = app(CdpConnectService::class);

        $requests->each(function ($payslipRequest) use ($cdpService) {
            $employee = $payslipRequest->employee;
            $period = $payslipRequest->period ?? now()->format('Y-m');

            $metrics = [
                'achievement_percentage' => 0,
                'payment_percentage' => 0,
                'payment_criteria' => 'No',
                'calculated_payment' => 0,
            ];

            if ($employee && $employee->employee_code) {
                try {
                    $cdpUser = $cdpService->fetchEmployeeMetrics($employee->employee_code, $period);
                    if ($cdpUser && isset($cdpUser['metrics'])) {
                        $m = $cdpUser['metrics'];
                        $achievement = (float) ($m['achievement_percentage'] ?? $m['performance_percentage'] ?? $m['achievement'] ?? $m['performance'] ?? $m['score'] ?? 0);

                        $salaryOverride = $employee->salaryDetail;
                        $getSalaryField = function ($field) use ($salaryOverride, $employee) {
                            if ($salaryOverride && ! is_null($salaryOverride->{$field})) {
                                return (float) $salaryOverride->{$field};
                            }
                            $designation = $employee->designation;
                            return $designation ? (float) ($designation->{$field} ?? 0) : 0.0;
                        };

                        $totalPackage = $getSalaryField('total_package');
                        $mobilePayment = $getSalaryField('mobile_payment');
                        $basicSalary = $getSalaryField('basic_salary');

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
                        if ($achievement < 50 && $isPermanent) {
                            $calculatedPayment = $basicSalary;
                        }

                        if ($achievement > 90) {
                            $calculatedPayment = $totalPackage + $mobilePayment;
                        }

                        $metrics = [
                            'achievement_percentage' => $achievement,
                            'payment_percentage' => $paymentPercentage,
                            'payment_criteria' => $paymentCriteria,
                            'calculated_payment' => $calculatedPayment,
                            'total_package' => $totalPackage,
                        ];
                    }
                } catch (\Throwable $th) {
                    \Log::warning('Failed to fetch metrics for pending request', [
                        'employee_id' => $employee?->id,
                        'error' => $th->getMessage(),
                    ]);
                }
            }

            $payslipRequest->metrics = $metrics;
        });
    }

    /**
     * Approve a payslip request with e-signature
     */
    public function approveRequest(Request $request, $requestId)
    {
        try {
            $request->validate([
                'signature_data' => 'required|string'
            ]);

            $payslipRequest = PayslipRequest::with(['user', 'payrollRecord'])->findOrFail($requestId);

            DB::beginTransaction();

            // Generate signed payslip with e-signature
            $payrollRecord = $payslipRequest->payrollRecord;
            $user = $payslipRequest->user;
            $approver = Auth::user();

            // Handle period-based requests (payroll_record_id is null)
            if (! $payrollRecord) {
                $payrollRecord = PayrollRecord::where('user_id', $user->id)
                    ->where('month', 'like', '%'.$payslipRequest->period.'%')
                    ->first();
            }

            if (! $payrollRecord) {
                $employee = \App\Models\Employee::find($user->employee_id);
                $basic = $employee?->basic_salary ?? 0;
                $payrollRecord = new \stdClass();
                $payrollRecord->month = $payslipRequest->month_label ?? $payslipRequest->period ?? 'Unknown';
                $payrollRecord->basic = $basic;
                $payrollRecord->allowances = 0;
                $payrollRecord->deductions = 0;
                $payrollRecord->epf_employee = 0;
                $payrollRecord->epf_employer = 0;
                $payrollRecord->etf_employer = 0;
                $payrollRecord->net = $basic;
                $payrollRecord->id = 'N/A';
            }

            $period_label = $payrollRecord->month;
            $period = $payslipRequest->period ?? $payrollRecord->month;

            // Collect performance metrics
            $metrics = [
                'basic_salary' => 0,
                'vehicle_allowance' => 0,
                'travel_reimbursement' => 0,
                'performance_allowance' => 0,
                'incentive' => 0,
                'position_allowance' => 0,
                'mobile_payment' => 0,
                'total_package' => 0,
                'monthly_target' => 0,
                'achievement_percentage' => 0,
                'payment_percentage' => 0,
                'payment_criteria' => 'No',
                'calculated_payment' => 0,
                'commission' => 0,
                'override_commission' => 0,
                'total_commission' => 0,
                'recover_amount' => 0,
                'how_much_paid' => 0,
                'epf' => 0,
                'income_tax' => 0,
                'total_deductions' => 0,
                'net_pay' => 0,
                'employee_type' => '',
                'employee_code' => '',
            ];

            try {
                $employee = Employee::with(['designation', 'salaryDetail'])->find($user->employee_id);
                $salaryOverride = $employee?->salaryDetail;

                // Use employee salary override if present, otherwise fall back to designation
                $getSalaryField = function ($field) use ($salaryOverride, $employee) {
                    if ($salaryOverride && ! is_null($salaryOverride->{$field})) {
                        return (float) $salaryOverride->{$field};
                    }
                    $designation = $employee?->designation;
                    return $designation ? (float) ($designation->{$field} ?? 0) : 0.0;
                };

                if ($employee && $employee->designation) {
                    $metrics['basic_salary'] = $getSalaryField('basic_salary');
                    $metrics['vehicle_allowance'] = $getSalaryField('vehicle_rental');
                    $metrics['travel_reimbursement'] = $getSalaryField('travel_reimbursement');
                    $metrics['performance_allowance'] = $getSalaryField('performance_allowance');
                    $metrics['incentive'] = $getSalaryField('incentive');
                    $metrics['position_allowance'] = $getSalaryField('position_allowance');
                    $metrics['mobile_payment'] = $getSalaryField('mobile_payment');
                    $metrics['total_package'] = $getSalaryField('total_package');
                    $metrics['monthly_target'] = $getSalaryField('monthly_target');
                }

                $cdpService = app(CdpConnectService::class);
                $cdpUser = $employee?->employee_code ? $cdpService->fetchEmployeeMetrics($employee->employee_code, $period) : null;
                $achievement = null;
                if ($cdpUser && isset($cdpUser['metrics'])) {
                    $m = $cdpUser['metrics'];
                    $achievement = (float) ($m['achievement_percentage'] ?? $m['performance_percentage'] ?? $m['achievement'] ?? $m['performance'] ?? $m['score'] ?? 0);
                    $metrics['commission'] = (float) ($m['commission'] ?? 0);
                    $metrics['override_commission'] = (float) ($m['override_commission'] ?? 0);
                    $metrics['total_commission'] = (float) ($m['total_commission'] ?? 0);
                    $metrics['recover_amount'] = (float) ($m['recover_amount'] ?? 0);
                }
                if ($achievement === null) $achievement = 0;

                $metrics['achievement_percentage'] = $achievement;

                $isPermanent = ($employee && $employee->employee_type === 'permanent');

                if ($achievement < 50) {
                    $metrics['payment_percentage'] = 0;
                    $metrics['payment_criteria'] = $isPermanent ? 'Basic Salary (Guaranteed)' : 'No';
                } elseif ($achievement >= 50 && $achievement <= 65) {
                    $metrics['payment_percentage'] = 50;
                    $metrics['payment_criteria'] = '50% Total Package';
                } elseif ($achievement > 65 && $achievement <= 90) {
                    $metrics['payment_percentage'] = 75;
                    $metrics['payment_criteria'] = '75% Total Package';
                } else {
                    $metrics['payment_percentage'] = 100;
                    $metrics['payment_criteria'] = '100% Package + Mobile Bonus';
                }

                $metrics['calculated_payment'] = ($metrics['payment_percentage'] / 100) * $metrics['total_package'];

                // Permanent staff always receive basic salary floor even when <50% achievement
                if ($achievement < 50 && $isPermanent) {
                    $metrics['calculated_payment'] = $metrics['basic_salary'];
                }

                // For >90% achievement, add mobile_payment as bonus on top of full package
                if ($achievement > 90) {
                    $metrics['calculated_payment'] = $metrics['total_package'] + $metrics['mobile_payment'];
                }

                $metrics['employee_type'] = $employee?->employee_type ?? '';
                $metrics['employee_code'] = $employee?->employee_code ?? $user->employee_id ?? '';

                // Gross pay = calculated payment (mobile bonus already included for >90%)
                $metrics['how_much_paid'] = round($metrics['calculated_payment'], 2);

                // Deductions: EPF (8% of basic) + PAYE tax (taxable = basic - EPF) apply only to permanent staff.
                $epfEmployee = 0.0;
                $incomeTax = 0.0;
                if ($isPermanent) {
                    $epfEmployee = SriLankanTaxService::epfEmployee($metrics['basic_salary']);
                    $incomeTax = SriLankanTaxService::paye($metrics['basic_salary'] - $epfEmployee);
                }
                $metrics['epf'] = round($epfEmployee, 2);
                $metrics['income_tax'] = round($incomeTax, 2);

                // Sum monthly installments from the loans table that apply to this period
                $loanDeductions = LoanDeductionService::activeForPeriod((int) ($employee?->id ?? 0), (string) $period);
                $loanDeductionItems = $loanDeductions['items'];
                $loanDeductionsTotal = $loanDeductions['total'];

                $metrics['loan_deductions'] = $loanDeductionItems;
                $metrics['loan_deductions_total'] = round($loanDeductionsTotal, 2);
                $metrics['total_deductions'] = round($epfEmployee + $incomeTax + $metrics['recover_amount'] + $loanDeductionsTotal, 2);
                $metrics['net_pay'] = round($metrics['how_much_paid'] - $metrics['total_deductions'], 2);
            } catch (\Throwable $th) {
                \Log::warning('Failed to fetch metrics for payslip PDF', ['error' => $th->getMessage()]);
            }

            // Prepare data for PDF
            $data = [
                'payroll' => $payrollRecord,
                'user' => $user,
                'signature' => $request->signature_data,
                'approved_by' => $approver,
                'approved_date' => now(),
                'period_label' => $period_label,
                'metrics' => $metrics,
            ];

            // Generate PDF
            $pdf = Pdf::loadView('pdf.payslip', $data);
            // Save PDF to public/uploads/payslips (same pattern as FileUploadTrait)
            $directory = 'uploads/payslips';
            if (!File::exists(public_path($directory))) {
                File::makeDirectory(public_path($directory), 0755, true);
            }

            $cleanMonth = str_replace(' ', '_', $period_label);
            $fileName = "signed_{$user->id}_{$payslipRequest->period}_{$cleanMonth}.pdf";
            $filePath = "{$directory}/{$fileName}";

            File::put(public_path($filePath), $pdf->output());

            // Update request with file path
            $payslipRequest->update([
                'status' => 'approved',
                'employee_id' => $user->employee_id,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'signed_file_path' => $filePath
            ]);

            DB::commit();

            $this->logActivity('APPROVE', 'Payroll', "Approved payslip request (ID: {$payslipRequest->id}) for month {$period_label}");

            \Log::info('Payslip request approved', [
                'user_id' => Auth::id(),
                'request_id' => $payslipRequest->id,
                'employee_id' => $user->id,
                'month' => $period_label,
                'file_path' => $filePath
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Payslip request approved and signed successfully',
                'data' => $payslipRequest
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Failed to approve payslip request', [
                'user_id' => Auth::id(),
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject a payslip request
     */
    public function rejectRequest(Request $request, $requestId)
    {
        try {
            $request->validate([
                'rejection_reason' => 'required|string|max:500'
            ]);

            $payslipRequest = PayslipRequest::findOrFail($requestId);

            $payslipRequest->update([
                'status' => 'rejected',
                'rejection_reason' => $request->rejection_reason
            ]);

            $this->logActivity('REJECT', 'Payroll', "Rejected payslip request (ID: {$payslipRequest->id}). Reason: {$request->rejection_reason}", $request->only(['rejection_reason']));

            \Log::info('Payslip request rejected', [
                'user_id' => Auth::id(),
                'request_id' => $payslipRequest->id,
                'reason' => $request->rejection_reason
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Payslip request rejected successfully',
                'data' => $payslipRequest
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to reject payslip request', [
                'user_id' => Auth::id(),
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all payroll records (admin view)
     */
    public function getAllPayrolls(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = PayrollRecord::with(['user', 'latestRequest']);

            if ($request->has('month')) {
                $query->where('month', $request->month);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->whereHas('user', function($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                      ->orWhere('email', 'like', '%' . $search . '%')
                      ->orWhere('employee_id', 'like', '%' . $search . '%');
                });
            }

            // Only HR users with the activate permission can list non-activated months
            if (! Auth::user()->can(PayrollActivationService::MANAGE_PERMISSION)) {
                $visibleNormalized = PayrollMonth::where('status', '!=', 'inactive')
                    ->pluck('month')
                    ->all();

                $allowedRawMonths = PayrollRecord::distinct()
                    ->pluck('month')
                    ->filter(function ($raw) use ($visibleNormalized) {
                        $normalized = PayrollActivationService::normalizeMonth($raw);
                        return $normalized !== null && in_array($normalized, $visibleNormalized, true);
                    })
                    ->all();

                $query->whereIn('month', $allowedRawMonths);
            }

            $payrolls = $query->orderBy('month', 'desc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Payroll records retrieved successfully',
                'data' => $payrolls
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to retrieve all payrolls', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve payroll records: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single payroll record details
     */
    public function getPayrollDetails($id)
    {
        try {
            $payroll = PayrollRecord::with(['user', 'payslipRequests.approver'])->findOrFail($id);

            if (! PayrollActivationService::canView($payroll->month, Auth::user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payroll for this month has not been activated yet.',
                ], 403);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payroll details retrieved successfully',
                'data' => $payroll
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payroll record not found'
            ], 404);
        }
    }

    /**
     * Get the activation status of a payroll month.
     */
    public function getMonthStatus(Request $request)
    {
        try {
            $request->validate(['month' => 'required|string']);

            $month = $request->month;
            $record = PayrollActivationService::getOrCreate($month);

            if (! $record) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid month format',
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'month' => $record->month,
                    'status' => $record->status,
                    'activated_by' => $record->activator?->name ?? null,
                    'activated_at' => $record->activated_at,
                    'locked_by' => $record->locker?->name ?? null,
                    'locked_at' => $record->locked_at,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve month status: '.$th->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate a payroll month so everyone can view its details.
     */
    public function activateMonth(Request $request)
    {
        try {
            $request->validate(['month' => 'required|string']);

            $record = PayrollActivationService::getOrCreate($request->month);
            if (! $record) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid month format',
                ], 422);
            }

            if ($record->isLocked()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This payroll month is locked and cannot be changed.',
                ], 422);
            }

            $record->update([
                'status' => 'active',
                'activated_by' => Auth::id(),
                'activated_at' => now(),
            ]);

            $this->logActivity('UPDATE', 'Payroll Month', "Activated payroll month {$record->month}");

            return response()->json([
                'status' => 'success',
                'message' => "Payroll for {$record->month} activated successfully",
                'data' => $record,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to activate payroll month: '.$th->getMessage(),
            ], 500);
        }
    }

    /**
     * Lock a payroll month so it can no longer be modified.
     */
    public function lockMonth(Request $request)
    {
        try {
            $request->validate(['month' => 'required|string']);

            $record = PayrollActivationService::getOrCreate($request->month);
            if (! $record) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid month format',
                ], 422);
            }

            $record->update([
                'status' => 'locked',
                'locked_by' => Auth::id(),
                'locked_at' => now(),
            ]);

            $this->logActivity('UPDATE', 'Payroll Month', "Locked payroll month {$record->month}");

            return response()->json([
                'status' => 'success',
                'message' => "Payroll for {$record->month} locked",
                'data' => $record,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to lock payroll month: '.$th->getMessage(),
            ], 500);
        }
    }

    /**
     * Update payroll record
     */
    public function updatePayroll(Request $request, $id)
    {
        try {
            $request->validate([
                'basic' => 'nullable|numeric|min:0',
                'allowances' => 'nullable|numeric|min:0',
                'deductions' => 'nullable|numeric|min:0',
                'status' => 'nullable|in:draft,pending,processed'
            ]);

            $payroll = PayrollRecord::findOrFail($id);

            if (PayrollActivationService::isLocked($payroll->month)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This payroll month is locked and cannot be modified.',
                ], 403);
            }

            $updateData = [];
            if ($request->has('basic')) $updateData['basic'] = $request->basic;
            if ($request->has('allowances')) $updateData['allowances'] = $request->allowances;
            if ($request->has('deductions')) $updateData['deductions'] = $request->deductions;
            if ($request->has('status')) $updateData['status'] = $request->status;

            // Recalculate net and EPF if earnings changed
            if (isset($updateData['basic']) || isset($updateData['allowances']) || isset($updateData['deductions'])) {
                $basic = $updateData['basic'] ?? $payroll->basic;
                $allowances = $updateData['allowances'] ?? $payroll->allowances;
                $deductions = $updateData['deductions'] ?? $payroll->deductions;
                $gross = $basic + $allowances;

                $updateData['epf_employee'] = $gross * 0.08;
                $updateData['epf_employer'] = $gross * 0.12;
                $updateData['etf_employer'] = $gross * 0.03;
                $updateData['net'] = $gross - $updateData['epf_employee'] - $deductions;
            }

            $payroll->update($updateData);

            $this->logActivity('UPDATE', 'Payroll', "Updated payroll record (ID: {$payroll->id}) for month {$payroll->month}", $updateData);

            \Log::info('Payroll record updated', [
                'user_id' => Auth::id(),
                'payroll_id' => $payroll->id,
                'updated_fields' => array_keys($updateData)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Payroll record updated successfully',
                'data' => $payroll
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update payroll record: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process payroll (change status from draft to processed)
     */
    public function processPayroll($id)
    {
        try {
            $payroll = PayrollRecord::findOrFail($id);

            if (PayrollActivationService::isLocked($payroll->month)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This payroll month is locked and cannot be modified.',
                ], 403);
            }

            $payroll->update([
                'status' => 'processed',
                'processed_at' => now()
            ]);

            $this->logActivity('PROCESS', 'Payroll', "Processed payroll record (ID: {$payroll->id}) for month {$payroll->month}");

            \Log::info('Payroll processed', [
                'user_id' => Auth::id(),
                'payroll_id' => $payroll->id,
                'employee_id' => $payroll->user_id,
                'month' => $payroll->month
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Payroll processed successfully',
                'data' => $payroll
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process payroll: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk generate payroll for multiple employees
     */
    public function bulkGenerate(Request $request)
    {
        try {
            $request->validate([
                'month' => 'required|string',
                'user_ids' => 'required|array',
                'user_ids.*' => 'exists:users,id'
            ]);

            if (PayrollActivationService::isLocked($request->month)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This payroll month is locked and cannot be regenerated.',
                ], 403);
            }

            $generated = [];
            $errors = [];

            foreach ($request->user_ids as $userId) {
                try {
                    $user = User::find($userId);

                    // Calculate salary components (adjust based on your business logic)
                    $basic = $user->basic_salary ?? 0;
                    $allowances = $this->calculateAllowances($user);
                    $deductions = $this->calculateDeductions($user);
                    $gross = $basic + $allowances;
                    $epfEmployee = $gross * 0.08;
                    $epfEmployer = $gross * 0.12;
                    $etfEmployer = $gross * 0.03;
                    $net = $gross - $epfEmployee - $deductions;

                    $payroll = PayrollRecord::updateOrCreate(
                        [
                            'user_id' => $userId,
                            'month' => $request->month
                        ],
                        [
                            'basic' => $basic,
                            'allowances' => $allowances,
                            'deductions' => $deductions,
                            'net' => $net,
                            'epf_employee' => $epfEmployee,
                            'epf_employer' => $epfEmployer,
                            'etf_employer' => $etfEmployer,
                            'status' => 'draft'
                        ]
                    );

                    $generated[] = $payroll;

                } catch (\Exception $e) {
                    $errors[] = [
                        'user_id' => $userId,
                        'error' => $e->getMessage()
                    ];
                }
            }

            $this->logActivity('GENERATE', 'Payroll', "Generated bulk payroll records for month {$request->month}", $request->all());

            \Log::info('Bulk payroll generated', [
                'user_id' => Auth::id(),
                'month' => $request->month,
                'generated_count' => count($generated),
                'error_count' => count($errors)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Payroll generated successfully',
                'data' => [
                    'generated' => $generated,
                    'errors' => $errors,
                    'total' => count($generated),
                    'failed' => count($errors)
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate payroll: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate allowances for an employee
     * Override this method based on your business rules
     */
    private function calculateAllowances($user)
    {
        $allowances = 0;

        // Example: Transport allowance
        if (isset($user->transport_allowance) && $user->transport_allowance) {
            $allowances += 5000;
        }

        // Example: Meal allowance
        if (isset($user->meal_allowance) && $user->meal_allowance) {
            $allowances += 3000;
        }

        // Default allowance
        $allowances += 15000;

        return $allowances;
    }

    /**
     * Calculate deductions for an employee
     * Override this method based on your business rules
     */
    private function calculateDeductions($user)
    {
        $deductions = 0;

        // Example: Loan deductions
        if (isset($user->loan_deduction) && $user->loan_deduction > 0) {
            $deductions += $user->loan_deduction;
        }

        return $deductions;
    }
}
