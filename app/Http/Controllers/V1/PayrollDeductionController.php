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
                ->with('loan')
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

            // Delete existing non-auto deductions (admin-added ones)
            PayrollDeduction::where('payroll_record_id', $payrollRecordId)
                ->where('is_auto', false)
                ->delete();

            // Create new deductions
            $created = [];
            foreach ($request->deductions as $deduction) {
                $created[] = PayrollDeduction::create([
                    'payroll_record_id' => $payrollRecordId,
                    'type' => $deduction['type'],
                    'label' => $deduction['label'],
                    'amount' => $deduction['amount'],
                    'loan_id' => $deduction['loan_id'] ?? null,
                    'is_auto' => false,
                ]);
            }

            // Recalculate total deductions and update payroll record
            $totalDeductions = PayrollDeduction::where('payroll_record_id', $payrollRecordId)
                ->sum('amount');

            $gross = $payrollRecord->basic + $payrollRecord->allowances;
            $net = $gross - $payrollRecord->epf_employee - $totalDeductions;

            $payrollRecord->update([
                'deductions' => $totalDeductions,
                'net' => $net,
            ]);

            DB::commit();

            $this->logActivity('UPDATE', 'Payroll Deductions', "Updated deductions for payroll record ID: {$payrollRecordId}. Total: {$totalDeductions}");

            return response()->json([
                'status' => 'success',
                'message' => 'Deductions updated successfully',
                'data' => $created,
                'total_deductions' => $totalDeductions,
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

            $deduction->delete();

            // Recalculate
            $totalDeductions = PayrollDeduction::where('payroll_record_id', $payrollRecordId)
                ->sum('amount');

            $gross = $payrollRecord->basic + $payrollRecord->allowances;
            $net = $gross - $payrollRecord->epf_employee - $totalDeductions;

            $payrollRecord->update([
                'deductions' => $totalDeductions,
                'net' => $net,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Deduction removed',
                'total_deductions' => $totalDeductions,
                'net_pay' => $net,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete deduction: ' . $e->getMessage(),
            ], 500);
        }
    }
}
