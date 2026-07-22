<?php

// app/Http/Controllers/V1/PayrollController.php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\PayrollRecord;
use App\Models\PayslipRequest;
use App\Models\Employee;
use App\Services\CdpConnectService;
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

            $payslipRequest = PayslipRequest::where('user_id', Auth::id())
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

            $employee = Employee::with('designation')->find($employeeId);

            if (! $employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found',
                ], 404);
            }

            $designation = $employee->designation;
            $totalPackage = $designation ? (float) ($designation->total_package ?? 0) : 0.0;

            // Fetch metrics from external API service
            $period = $request->get('period_key', now()->format('Y-m'));

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
            // Below 50% -> No (0%)
            // 50% - 65% -> 50% Total Package
            // >65% - 90% -> 75% Total Package
            // Over 90% -> 100% Total Package + Mobile Payment
            $paymentPercentage = 0;
            $paymentCriteria = 'No';

            if ($achievement < 50) {
                $paymentPercentage = 0;
                $paymentCriteria = 'No';
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

            // For >90% achievement, add mobile_payment as bonus on top of full package
            $mobilePaymentBonus = 0.0;
            if ($achievement > 90) {
                $calculatedPayment = $totalPackage;
                $mobilePaymentBonus = $designation ? (float) ($designation->mobile_payment ?? 0) : 0.0;
            }

            $monthlyTarget = $designation ? (float) ($designation->monthly_target ?? 0) : 0.0;
            $basicSalary = $designation ? (float) ($designation->basic_salary ?? 0) : 0.0;
            $travelReimbursement = $designation ? (float) ($designation->travel_reimbursement ?? 0) : 0.0;
            $vehicleAllowance = $designation ? (float) ($designation->vehicle_rental ?? 0) : 0.0;
            $performanceAllowance = $designation ? (float) ($designation->performance_allowance ?? 0) : 0.0;
            $incentive = $designation ? (float) ($designation->incentive ?? 0) : 0.0;
            $positionAllowance = $designation ? (float) ($designation->position_allowance ?? 0) : 0.0;
            $mobilePayment = $designation ? (float) ($designation->mobile_payment ?? 0) : 0.0;
            $howMuchPaid = $calculatedPayment + $mobilePaymentBonus;
            return response()->json([
                'status' => 'success',
                'data' => [
                    'employee_id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'full_name' => $employee->full_name,
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
                    'period' => $period,
                    'metrics_found' => ! $metricsNotFound,
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
