<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\PayrollDeduction;
use App\Models\PayrollRecord;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Traits\ActivityLogTrait;

class PayrollDeductionController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public const DEDUCTION_TYPES = [
        'epf_employee',
        'loan',
        'advance',
        'absent',
        'late',
        'tax',
        'penalty',
        'policy_cancellation',
        'unauthorized',
        'other',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Payroll Deduction Index', only: ['index', 'pendingDeductions']),
            new Middleware('permission:Payroll Deduction Create', only: ['store']),
            new Middleware('permission:Payroll Deduction Delete', only: ['destroy']),
            new Middleware('permission:Payroll Deduction Approve', only: ['approve', 'bulkApprove']),
            new Middleware('permission:Payroll Deduction Reject', only: ['reject', 'bulkReject']),
            new Middleware('permission:Payroll Deduction Report', only: ['report', 'reportCsv', 'summary']),
        ];
    }

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
                'deductions.*.type' => 'required|in:' . implode(',', self::DEDUCTION_TYPES),
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
            $deduction = PayrollDeduction::with('payrollRecord')->findOrFail($deductionId);

            $payrollRecord = $deduction->payrollRecord;
            if ($payrollRecord && \App\Services\PayrollActivationService::isLocked($payrollRecord->month)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This payroll month is locked and cannot be modified.',
                ], 403);
            }

            if ($deduction->approval_status === 'approved') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This deduction is already approved.',
                ], 422);
            }

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

            $deduction = PayrollDeduction::with('payrollRecord')->findOrFail($deductionId);

            $payrollRecord = $deduction->payrollRecord;
            if ($payrollRecord && \App\Services\PayrollActivationService::isLocked($payrollRecord->month)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This payroll month is locked and cannot be modified.',
                ], 403);
            }

            if ($deduction->approval_status === 'rejected') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This deduction is already rejected.',
                ], 422);
            }

            $wasApproved = $deduction->approval_status === 'approved';

            $deduction->update([
                'approval_status' => 'rejected',
                'rejection_reason' => $request->rejection_reason,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            // Rejecting an already-approved deduction removes it from net pay, so recalculate.
            if ($wasApproved) {
                $this->recalculatePayroll($deduction->payroll_record_id);
            }

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

    /**
     * Approve every pending deduction on a payroll record in one go.
     */
    public function bulkApprove(Request $request, $payrollRecordId)
    {
        try {
            $payrollRecord = PayrollRecord::findOrFail($payrollRecordId);

            if (\App\Services\PayrollActivationService::isLocked($payrollRecord->month)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This payroll month is locked and cannot be modified.',
                ], 403);
            }

            $updated = PayrollDeduction::where('payroll_record_id', $payrollRecordId)
                ->where('approval_status', 'pending')
                ->update([
                    'approval_status' => 'approved',
                    'rejection_reason' => null,
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                ]);

            $this->recalculatePayroll($payrollRecordId);

            $this->logActivity('APPROVE', 'Payroll Deductions', "Bulk approved {$updated} pending deduction(s) for payroll record ID: {$payrollRecordId}");

            return response()->json([
                'status' => 'success',
                'message' => $updated > 0
                    ? "Approved {$updated} deduction(s)"
                    : 'No pending deductions to approve',
                'approved_count' => $updated,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to bulk approve deductions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject every pending deduction on a payroll record in one go.
     */
    public function bulkReject(Request $request, $payrollRecordId)
    {
        try {
            $request->validate([
                'rejection_reason' => 'required|string|max:500',
            ]);

            $payrollRecord = PayrollRecord::findOrFail($payrollRecordId);

            if (\App\Services\PayrollActivationService::isLocked($payrollRecord->month)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This payroll month is locked and cannot be modified.',
                ], 403);
            }

            $updated = PayrollDeduction::where('payroll_record_id', $payrollRecordId)
                ->where('approval_status', 'pending')
                ->update([
                    'approval_status' => 'rejected',
                    'rejection_reason' => $request->rejection_reason,
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                ]);

            $this->recalculatePayroll($payrollRecordId);

            $this->logActivity('REJECT', 'Payroll Deductions', "Bulk rejected {$updated} pending deduction(s) for payroll record ID: {$payrollRecordId}. Reason: {$request->rejection_reason}");

            return response()->json([
                'status' => 'success',
                'message' => $updated > 0
                    ? "Rejected {$updated} deduction(s)"
                    : 'No pending deductions to reject',
                'rejected_count' => $updated,
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
                'message' => 'Failed to bulk reject deductions: ' . $e->getMessage(),
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
     * Paginated deduction report with approval status breakdowns.
     *
     * Filters: month (YYYY-MM), approval_status, type, department_id,
     *          branch_id, employee_id, search
     */
    public function report(Request $request)
    {
        try {
            $filters = $this->validateReportFilters($request);
            $perPage = (int) $request->get('per_page', 25);
            $perPage = max(1, min($perPage, 200));

            $query = $this->buildReportQuery($filters);

            $rows = (clone $query)
                ->with(['loan', 'approver', 'payrollRecord.employee.department', 'payrollRecord.employee.branch'])
                ->orderByRaw("(CASE WHEN employees.employee_code REGEXP '^[0-9]+$' THEN 0 ELSE 1 END) ASC, CAST(REGEXP_SUBSTR(employees.employee_code, '[0-9]+') AS UNSIGNED) ASC, employees.employee_code ASC")
                ->paginate($perPage);

            $totals = $this->buildReportTotals(clone $query);

            return response()->json([
                'status' => 'success',
                'message' => 'Deduction report retrieved successfully',
                'data' => $rows,
                'summary' => $totals,
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
                'message' => 'Failed to generate deduction report: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Compact aggregate totals — powers report/dashboard header cards.
     */
    public function summary(Request $request)
    {
        try {
            $filters = $this->validateReportFilters($request);

            $totals = $this->buildReportTotals($this->buildReportQuery($filters));

            return response()->json([
                'status' => 'success',
                'message' => 'Deduction summary retrieved successfully',
                'data' => $totals,
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
                'message' => 'Failed to generate deduction summary: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Stream the deduction report as a CSV download.
     */
    public function reportCsv(Request $request): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        try {
            $filters = $this->validateReportFilters($request);

            $rows = $this->buildReportQuery($filters)
                ->with(['loan', 'approver', 'payrollRecord.employee'])
                ->orderByDesc('payroll_deductions.id')
                ->get();

            $month = $filters['month'] ?? 'all';
            $fileName = "payroll-deductions-report-{$month}.csv";

            return response()->streamDownload(function () use ($rows) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'Employee Code',
                    'Employee Name',
                    'Department',
                    'Month',
                    'Type',
                    'Label',
                    'Amount',
                    'Source',
                    'Approval Status',
                    'Rejection Reason',
                    'Approved By',
                    'Approved At',
                    'Created At',
                ]);

                foreach ($rows as $row) {
                    $employee = $row->payrollRecord?->employee;

                    fputcsv($handle, [
                        $employee?->employee_code,
                        $employee?->full_name,
                        $employee?->department?->name,
                        $row->payrollRecord?->month,
                        $row->type,
                        $row->label,
                        number_format((float) $row->amount, 2, '.', ''),
                        $row->is_auto ? 'Auto' : 'Manual',
                        $row->approval_status,
                        $row->rejection_reason,
                        $row->approver?->name,
                        $row->approved_at?->toDateTimeString(),
                        $row->created_at?->toDateTimeString(),
                    ]);
                }

                fclose($handle);
            }, $fileName, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
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
                'message' => 'Failed to export deduction report: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function validateReportFilters(Request $request): array
    {
        return $request->validate([
            'month' => 'nullable|string|max:7',
            'approval_status' => 'nullable|in:pending,approved,rejected',
            'type' => 'nullable|string|in:' . implode(',', self::DEDUCTION_TYPES),
            'department_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'employee_id' => 'nullable|integer',
            'payroll_record_id' => 'nullable|integer',
            'search' => 'nullable|string|max:255',
        ]);
    }

    /**
     * Base query joining deductions to their payroll record and employee.
     */
    private function buildReportQuery(array $filters)
    {
        $query = PayrollDeduction::query()
            ->select('payroll_deductions.*')
            ->join('payroll_records', 'payroll_records.id', '=', 'payroll_deductions.payroll_record_id')
            ->join('employees', 'employees.id', '=', 'payroll_records.employee_id');

        if (!empty($filters['month'])) {
            $query->where('payroll_records.month', 'like', $filters['month'] . '%');
        }

        if (!empty($filters['approval_status'])) {
            $query->where('payroll_deductions.approval_status', $filters['approval_status']);
        }

        if (!empty($filters['type'])) {
            $query->where('payroll_deductions.type', $filters['type']);
        }

        if (!empty($filters['payroll_record_id'])) {
            $query->where('payroll_deductions.payroll_record_id', $filters['payroll_record_id']);
        }

        if (!empty($filters['employee_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('payroll_records.employee_id', $filters['employee_id'])
                    ->orWhere('payroll_records.user_id', $filters['employee_id']);
            });
        }

        if (!empty($filters['department_id']) || !empty($filters['branch_id'])) {
            $query->whereHas('payrollRecord.employee', function ($q) use ($filters) {
                if (!empty($filters['department_id'])) {
                    $q->where('department_id', $filters['department_id']);
                }
                if (!empty($filters['branch_id'])) {
                    $q->where('branch_id', $filters['branch_id']);
                }
            });
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('payroll_deductions.label', 'like', $search)
                    ->orWhereHas('payrollRecord.employee', function ($eq) use ($search) {
                        $eq->where('full_name', 'like', $search)
                            ->orWhere('employee_code', 'like', $search);
                    });
            });
        }

        return $query;
    }

    /**
     * Aggregate totals grouped by type and approval status.
     */
    private function buildReportTotals($query): array
    {
        // Clear the inherited select so ONLY_FULL_GROUP_BY is not violated.
        $byStatus = (clone $query)
            ->select([])
            ->selectRaw('payroll_deductions.approval_status, COUNT(*) as count, COALESCE(SUM(payroll_deductions.amount), 0) as total')
            ->groupBy('payroll_deductions.approval_status')
            ->get()
            ->keyBy('approval_status');

        $byType = (clone $query)
            ->select([])
            ->selectRaw('payroll_deductions.type, COUNT(*) as count, COALESCE(SUM(payroll_deductions.amount), 0) as total')
            ->groupBy('payroll_deductions.type')
            ->get()
            ->keyBy('type');

        $statusSummary = [];
        $grandTotal = 0.0;
        $totalCount = 0;

        foreach (['pending', 'approved', 'rejected'] as $status) {
            $row = $byStatus->get($status);
            $amount = round((float) ($row->total ?? 0), 2);
            $count = (int) ($row->count ?? 0);

            $statusSummary[$status] = ['count' => $count, 'total' => $amount];
            $grandTotal += $amount;
            $totalCount += $count;
        }

        $typeBreakdown = [];
        foreach ($byType as $type => $row) {
            $typeBreakdown[] = [
                'type' => $type,
                'label' => str_replace('_', ' ', ucfirst((string) $type)),
                'count' => (int) $row->count,
                'total' => round((float) $row->total, 2),
            ];
        }
        usort($typeBreakdown, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'total_count' => $totalCount,
            'total_amount' => round($grandTotal, 2),
            'by_status' => $statusSummary,
            'by_type' => $typeBreakdown,
        ];
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
