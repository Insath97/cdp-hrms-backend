<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\PayrollDeduction;
use App\Models\PayrollRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Traits\ActivityLogTrait;

class PayrollDeductionController extends Controller
{
    use ActivityLogTrait;

    public function index(Request $request, $payrollRecordId)
    {
        try {
            $deductions = PayrollDeduction::where('payroll_record_id', $payrollRecordId)
                ->with('loan', 'approver')
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $deductions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve deductions: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request, $payrollRecordId)
    {
        try {
            $request->validate([
                'deductions' => 'required|array',
                'deductions.*.type' => 'required|in:epf_employee,loan,advance,absent,late,tax,penalty,policy_cancellation,other',
                'deductions.*.label' => 'required|string|max:255',
                'deductions.*.amount' => 'required|numeric|min:0',
                'deductions.*.loan_id' => 'nullable|exists:loans,id',
            ]);

            $payrollRecord = PayrollRecord::findOrFail($payrollRecordId);

            if (\App\Services\PayrollActivationService::isLocked($payrollRecord->month)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This payroll month is locked and cannot be modified.',
                ], 403);
            }

            DB::beginTransaction();

            // Delete existing non-auto, non-approved deductions (preserve approved ones)
            PayrollDeduction::where('payroll_record_id', $payrollRecordId)
                ->where('is_auto', false)
                ->where('approval_status', '!=', 'approved')
                ->delete();

            // Create new deductions as pending
            $created = [];
            foreach ($request->deductions as $deduction) {
                $created[] = PayrollDeduction::create([
                    'payroll_record_id' => $payrollRecordId,
                    'type' => $deduction['type'],
                    'label' => $deduction['label'],
                    'amount' => $deduction['amount'],
                    'loan_id' => $deduction['loan_id'] ?? null,
                    'is_auto' => false,
                    'approval_status' => 'pending',
                ]);
            }

            // Recalculate from APPROVED deductions only (do not include pending)
            $approvedTotal = PayrollDeduction::where('payroll_record_id', $payrollRecordId)
                ->where('approval_status', 'approved')
                ->sum('amount');

            $gross = $payrollRecord->basic + $payrollRecord->allowances;
            $net = $gross - $payrollRecord->epf_employee - $approvedTotal;

            $payrollRecord->update([
                'total_deductions' => $approvedTotal,
                'net' => $net,
            ]);

            DB::commit();

            $this->logActivity('UPDATE', 'Payroll Deductions', "Submitted deductions for payroll record ID: {$payrollRecordId}. Pending: " . count($created) . " items. Approved total remains: {$approvedTotal}");

            return response()->json([
                'status' => 'success',
                'message' => 'Deductions submitted for approval',
                'data' => $created,
                'total_deductions' => $approvedTotal,
                'net_pay' => $net,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to save deductions: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($payrollRecordId, $deductionId)
    {
        try {
            $deduction = PayrollDeduction::where('payroll_record_id', $payrollRecordId)
                ->findOrFail($deductionId);

            $payrollRecord = PayrollRecord::findOrFail($payrollRecordId);

            if (\App\Services\PayrollActivationService::isLocked($payrollRecord->month)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This payroll month is locked and cannot be modified.',
                ], 403);
            }

            $wasApproved = $deduction->approval_status === 'approved';
            $deduction->delete();

            // If we deleted an approved deduction, recalculate
            if ($wasApproved) {
                $totalDeductions = PayrollDeduction::where('payroll_record_id', $payrollRecordId)
                    ->where('approval_status', 'approved')
                    ->sum('amount');

                $gross = $payrollRecord->basic + $payrollRecord->allowances;
                $net = $gross - $payrollRecord->epf_employee - $totalDeductions;

                $payrollRecord->update([
                    'total_deductions' => $totalDeductions,
                    'net' => $net,
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Approved deduction removed and totals recalculated',
                    'total_deductions' => $totalDeductions,
                    'net_pay' => $net,
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Pending deduction removed',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete deduction: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function approve($deductionId)
    {
        try {
            $deduction = PayrollDeduction::findOrFail($deductionId);

            $deduction->update([
                'approval_status' => 'approved',
                'rejection_reason' => null,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            // Recalculate payroll record from approved deductions only
            $this->recalculatePayroll($deduction->payroll_record_id);

            $deduction->load('approver');

            $this->logActivity('APPROVE', 'Payroll Deductions', "Approved deduction ID: {$deductionId} for payroll record ID: {$deduction->payroll_record_id}. Amount: {$deduction->amount}");

            return response()->json([
                'status' => 'success',
                'message' => 'Deduction approved',
                'data' => $deduction,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve deduction: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function reject(Request $request, $deductionId)
    {
        try {
            $request->validate([
                'rejection_reason' => 'required|string|max:500',
            ]);

            $deduction = PayrollDeduction::findOrFail($deductionId);

            $deduction->update([
                'approval_status' => 'rejected',
                'rejection_reason' => $request->rejection_reason,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            $this->logActivity('REJECT', 'Payroll Deductions', "Rejected deduction ID: {$deductionId} for payroll record ID: {$deduction->payroll_record_id}. Reason: {$request->rejection_reason}");

            return response()->json([
                'status' => 'success',
                'message' => 'Deduction rejected',
                'data' => $deduction,
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
                'message' => 'Failed to reject deduction: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function pendingDeductions($payrollRecordId)
    {
        try {
            $deductions = PayrollDeduction::where('payroll_record_id', $payrollRecordId)
                ->where('approval_status', 'pending')
                ->with('loan', 'approver')
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $deductions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve pending deductions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Recalculate PayrollRecord.deductions and net from approved deductions only.
     */
    private function recalculatePayroll(int $payrollRecordId): void
    {
        $payrollRecord = PayrollRecord::findOrFail($payrollRecordId);

        $approvedTotal = PayrollDeduction::where('payroll_record_id', $payrollRecordId)
            ->where('approval_status', 'approved')
            ->sum('amount');

        $gross = $payrollRecord->basic + $payrollRecord->allowances;
        $net = $gross - $payrollRecord->epf_employee - $approvedTotal;

        $payrollRecord->update([
            'total_deductions' => $approvedTotal,
            'net' => $net,
        ]);
    }
}
