<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\PayrollRecord;
use App\Models\PayslipRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\CdpConnectService;
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
            new Middleware('permission:Payroll Approve|Payroll View All', only: ['pendingRequests', 'approveRequest']),
            new Middleware('permission:Payroll Reject', only: ['rejectRequest']),
            new Middleware('permission:Payroll Generate', only: ['bulkGenerate']),
            new Middleware('permission:Payroll Update', only: ['updatePayroll']),
            new Middleware('permission:Payroll Process', only: ['processPayroll']),
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
            ];

            try {
                $employee = Employee::with('designation')->find($user->employee_id);
                if ($employee && $employee->designation) {
                    $des = $employee->designation;
                    $metrics['basic_salary'] = (float) ($des->basic_salary ?? 0);
                    $metrics['vehicle_allowance'] = (float) ($des->vehicle_rental ?? 0);
                    $metrics['travel_reimbursement'] = (float) ($des->travel_reimbursement ?? 0);
                    $metrics['performance_allowance'] = (float) ($des->performance_allowance ?? 0);
                    $metrics['incentive'] = (float) ($des->incentive ?? 0);
                    $metrics['position_allowance'] = (float) ($des->position_allowance ?? 0);
                    $metrics['mobile_payment'] = (float) ($des->mobile_payment ?? 0);
                    $metrics['total_package'] = (float) ($des->total_package ?? 0);
                    $metrics['monthly_target'] = (float) ($des->monthly_target ?? 0);
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
                }
                if ($achievement === null) $achievement = 0;

                $metrics['achievement_percentage'] = $achievement;

                if ($achievement < 50) {
                    $metrics['payment_percentage'] = 0;
                    $metrics['payment_criteria'] = 'No';
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

                // For >90% achievement, add mobile_payment as bonus on top of full package
                if ($achievement > 90) {
                    $metrics['calculated_payment'] = $metrics['total_package'] + $metrics['mobile_payment'];
                }
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
