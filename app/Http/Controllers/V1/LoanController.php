<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use App\Traits\ActivityLogTrait;

class LoanController extends Controller
{
    use ActivityLogTrait;

    public function index(Request $request)
    {
        try {
            $feed = $this->buildLoanFeed($request);

            return response()->json([
                'status' => 'success',
                'data' => $this->paginateFeed($feed, $request),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve loans: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Build the loan feed: single (non-bulk) loans as individual items, and
     * bulk batches collapsed into ONE row per batch so approval can be done once.
     */
    private function buildLoanFeed(Request $request, bool $pendingOnly = false): Collection
    {
        $search = $request->has('search') ? $request->search : null;

        // Singles (not part of a bulk batch)
        $singleQuery = Loan::with('employee', 'approver')->whereNull('batch_key');

        if ($pendingOnly) {
            $singleQuery->where('approval_status', 'pending');
        } elseif ($request->has('approval_status')) {
            $singleQuery->where('approval_status', $request->approval_status);
        }
        if ($request->has('status')) {
            $singleQuery->where('status', $request->status);
        }
        if ($request->has('employee_id')) {
            $singleQuery->where('employee_id', $request->employee_id);
        }
        if ($search) {
            $singleQuery->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }

        $singles = $singleQuery->get()->map(function ($l) {
            return [
                'id' => $l->id,
                'employee_id' => $l->employee_id,
                'employee' => $l->employee,
                'loan_type' => $l->loan_type,
                'description' => $l->description,
                'total_amount' => $l->total_amount,
                'monthly_installment' => $l->monthly_installment,
                'remaining_amount' => $l->remaining_amount,
                'effective_from' => $l->effective_from,
                'effective_to' => $l->effective_to,
                'start_date' => $l->start_date,
                'end_date' => $l->end_date,
                'status' => $l->status,
                'approval_status' => $l->approval_status,
                'rejection_reason' => $l->rejection_reason,
                'approved_by' => $l->approved_by,
                'approved_at' => $l->approved_at,
                'is_bulk' => false,
                'batch_key' => null,
                'approver' => $l->approver,
                'created_at' => $l->created_at,
            ];
        });

        // Bulk batches → one row per batch
        $bulkQuery = Loan::with('employee', 'approver')->whereNotNull('batch_key');
        if ($request->has('status')) {
            $bulkQuery->where('status', $request->status);
        }

        $groups = $bulkQuery->get()
            ->groupBy('batch_key')
            ->map(function ($loans) {
                $loans = $loans->values();
                $first = $loans->first();
                $total = $loans->count();
                $approved = $loans->where('approval_status', 'approved')->count();
                $rejected = $loans->where('approval_status', 'rejected')->count();
                $pending = $total - $approved - $rejected;

                if ($pending === $total) {
                    $approvalStatus = 'pending';
                } elseif ($approved === $total) {
                    $approvalStatus = 'approved';
                } elseif ($rejected === $total) {
                    $approvalStatus = 'rejected';
                } else {
                    $approvalStatus = 'mixed';
                }

                return [
                    'id' => $first->id,
                    'employee_id' => null,
                    'employee' => null,
                    'loan_type' => $first->loan_type,
                    'description' => $first->description,
                    'total_amount' => (float) $first->total_amount,
                    'bulk_total_amount' => (float) $loans->sum('total_amount'),
                    'monthly_installment' => $first->monthly_installment,
                    'remaining_amount' => $first->remaining_amount,
                    'effective_from' => $first->effective_from,
                    'effective_to' => $first->effective_to,
                    'start_date' => $first->start_date,
                    'end_date' => $first->end_date,
                    'status' => $first->status,
                    'approval_status' => $approvalStatus,
                    'rejection_reason' => $first->rejection_reason,
                    'approved_by' => $first->approved_by,
                    'approved_at' => $first->approved_at,
                    'is_bulk' => true,
                    'batch_key' => $first->batch_key,
                    'bulk_count' => $total,
                    'bulk_pending_count' => $pending,
                    'bulk_approved_count' => $approved,
                    'bulk_rejected_count' => $rejected,
                    'members' => $loans->map(function ($l) {
                        return [
                            'id' => $l->id,
                            'employee_id' => $l->employee_id,
                            'full_name' => $l->employee?->full_name,
                            'employee_code' => $l->employee?->employee_code,
                            'approval_status' => $l->approval_status,
                        ];
                    })->all(),
                    'approver' => $first->approver,
                    'created_at' => $first->created_at,
                ];
            })
            ->values();

        if ($pendingOnly) {
            $groups = $groups->filter(fn ($g) => $g['bulk_pending_count'] > 0)->values();
        } elseif ($request->has('approval_status')) {
            $groups = $groups->filter(fn ($g) => $g['approval_status'] === $request->approval_status)->values();
        }

        if ($search) {
            $groups = $groups->filter(function ($g) use ($search) {
                foreach ($g['members'] as $m) {
                    if (
                        mb_stripos((string) $m['full_name'], $search) !== false
                        || mb_stripos((string) $m['employee_code'], $search) !== false
                    ) {
                        return true;
                    }
                }
                return false;
            })->values();
        }

        return $singles->concat($groups)->sortByDesc('created_at')->values();
    }

    private function paginateFeed(Collection $feed, Request $request): LengthAwarePaginator
    {
        $perPage = (int) $request->get('per_page', 15);
        $currentPage = Paginator::resolveCurrentPage('page') ?: 1;
        $total = $feed->count();
        $items = $feed->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($items, $total, $perPage, $currentPage, [
            'path' => Paginator::resolveCurrentPath(),
            'query' => $request->query(),
        ]);
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'loan_type' => 'required|string|max:100',
                'description' => 'nullable|string|max:500',
                'total_amount' => 'required|numeric|min:0',
                'monthly_installment' => 'required|numeric|min:0',
                'effective_from' => 'required|string|date_format:Y-m',
                'effective_to' => 'nullable|string|date_format:Y-m|after_or_equal:effective_from',
            ]);

            $start = Carbon::createFromFormat('Y-m', $request->effective_from)->startOfMonth()->toDateString();
            $end = $request->effective_to ? Carbon::createFromFormat('Y-m', $request->effective_to)->endOfMonth()->toDateString() : null;

            $loan = Loan::create([
                'employee_id' => $request->employee_id,
                'loan_type' => $request->loan_type,
                'description' => $request->description,
                'total_amount' => $request->total_amount,
                'monthly_installment' => $request->monthly_installment,
                'remaining_amount' => $request->total_amount,
                'effective_from' => $request->effective_from,
                'effective_to' => $request->effective_to,
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'active',
                'approval_status' => 'pending',
            ]);

            $loan->load('employee');

            $this->logActivity('CREATE', 'Loans', "Created loan (ID: {$loan->id}) for employee ID: {$loan->employee_id}. Amount: {$loan->total_amount}. Status: Pending Approval");

            return response()->json([
                'status' => 'success',
                'message' => 'Loan created successfully (pending approval)',
                'data' => $loan,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create loan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create the same deduction/loan for multiple employees at once.
     */
    public function bulkStore(Request $request)
    {
        try {
            $request->validate([
                'employee_ids' => 'required|array|min:1|max:1000',
                'employee_ids.*' => 'integer|exists:employees,id',
                'loan_type' => 'required|string|max:100',
                'description' => 'nullable|string|max:500',
                'total_amount' => 'required|numeric|min:0',
                'monthly_installment' => 'required|numeric|min:0',
                'effective_from' => 'required|string|date_format:Y-m',
                'effective_to' => 'nullable|string|date_format:Y-m|after_or_equal:effective_from',
            ]);

            $start = Carbon::createFromFormat('Y-m', $request->effective_from)->startOfMonth()->toDateString();
            $end = $request->effective_to ? Carbon::createFromFormat('Y-m', $request->effective_to)->endOfMonth()->toDateString() : null;

            DB::beginTransaction();

            $batchKey = 'bulk_' . Str::uuid();
            $created = [];
            foreach (array_unique($request->employee_ids) as $employeeId) {
                $created[] = Loan::create([
                    'employee_id' => $employeeId,
                    'loan_type' => $request->loan_type,
                    'description' => $request->description,
                    'batch_key' => $batchKey,
                    'total_amount' => $request->total_amount,
                    'monthly_installment' => $request->monthly_installment,
                    'remaining_amount' => $request->total_amount,
                    'effective_from' => $request->effective_from,
                    'effective_to' => $request->effective_to,
                    'start_date' => $start,
                    'end_date' => $end,
                    'status' => 'active',
                    'approval_status' => 'pending',
                ]);
            }

            DB::commit();

            $this->logActivity('BULK_CREATE', 'Loans', "Bulk created {$request->loan_type} deduction for " . count($created) . " employees. Amount: {$request->total_amount}. Effective: {$request->effective_from}" . ($end ? " to " . $request->effective_to : " (ongoing)"));

            return response()->json([
                'status' => 'success',
                'message' => count($created) . ' deductions created (pending approval)',
                'data' => $created,
            ], 201);

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
                'message' => 'Failed to create deductions in bulk: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $loan = Loan::with('employee', 'deductions', 'approver')->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'data' => $loan,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Loan not found',
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $loan = Loan::findOrFail($id);

            $request->validate([
                'loan_type' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:500',
                'total_amount' => 'nullable|numeric|min:0',
                'monthly_installment' => 'nullable|numeric|min:0',
                'effective_from' => 'nullable|string|date_format:Y-m',
                'effective_to' => 'nullable|string|date_format:Y-m',
                'status' => 'nullable|in:active,completed,cancelled',
            ]);

            $data = $request->only([
                'loan_type', 'description', 'total_amount',
                'monthly_installment', 'effective_from', 'effective_to', 'status',
            ]);

            // Keep legacy start_date / end_date columns in sync with the effective months
            if (isset($data['effective_from'])) {
                $data['start_date'] = Carbon::createFromFormat('Y-m', $data['effective_from'])->startOfMonth()->toDateString();
            }
            if ($request->has('effective_to')) {
                $data['end_date'] = $request->effective_to
                    ? Carbon::createFromFormat('Y-m', $request->effective_to)->endOfMonth()->toDateString()
                    : null;
            }

            // If editing an approved loan, reset to pending for re-approval
            if ($loan->approval_status === 'approved') {
                $data['approval_status'] = 'pending';
                $data['rejection_reason'] = null;
                $data['approved_by'] = null;
                $data['approved_at'] = null;
            }

            $loan->update($data);

            $loan->load('employee');

            $this->logActivity('UPDATE', 'Loans', "Updated loan (ID: {$loan->id}) for employee ID: {$loan->employee_id}" . ($loan->approval_status === 'pending' ? '. Reset to pending approval.' : ''));

            return response()->json([
                'status' => 'success',
                'message' => $loan->approval_status === 'pending'
                    ? 'Loan updated and resubmitted for approval'
                    : 'Loan updated successfully',
                'data' => $loan,
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
                'message' => 'Failed to update loan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $loan = Loan::findOrFail($id);
            $loanData = $loan->toArray();
            $loan->delete();

            $this->logActivity('DELETE', 'Loans', "Deleted loan (ID: {$id})", $loanData);

            return response()->json([
                'status' => 'success',
                'message' => 'Loan deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete loan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getByEmployee($employeeId)
    {
        try {
            $loans = Loan::where('employee_id', $employeeId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $loans,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve loans: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function pendingApprovals(Request $request)
    {
        try {
            $feed = $this->buildLoanFeed($request, true);

            return response()->json([
                'status' => 'success',
                'data' => $this->paginateFeed($feed, $request),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve pending approvals: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function approve($id)
    {
        try {
            $loan = Loan::findOrFail($id);

            $loan->update([
                'approval_status' => 'approved',
                'rejection_reason' => null,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            $loan->load('employee', 'approver');

            $this->logActivity('APPROVE', 'Loans', "Approved loan (ID: {$loan->id}) for employee ID: {$loan->employee_id}. Amount: {$loan->total_amount}");

            return response()->json([
                'status' => 'success',
                'message' => 'Loan deduction approved',
                'data' => $loan,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve loan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function reject(Request $request, $id)
    {
        try {
            $request->validate([
                'rejection_reason' => 'required|string|max:500',
            ]);

            $loan = Loan::findOrFail($id);

            $loan->update([
                'approval_status' => 'rejected',
                'rejection_reason' => $request->rejection_reason,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            $loan->load('employee', 'approver');

            $this->logActivity('REJECT', 'Loans', "Rejected loan (ID: {$loan->id}) for employee ID: {$loan->employee_id}. Reason: {$request->rejection_reason}");

            return response()->json([
                'status' => 'success',
                'message' => 'Loan deduction rejected',
                'data' => $loan,
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
                'message' => 'Failed to reject loan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function bulkApprove($batchKey)
    {
        try {
            $loans = Loan::where('batch_key', $batchKey)->get();

            if ($loans->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bulk deduction batch not found',
                ], 404);
            }

            $updated = Loan::where('batch_key', $batchKey)
                ->where('approval_status', 'pending')
                ->update([
                    'approval_status' => 'approved',
                    'rejection_reason' => null,
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                ]);

            $this->logActivity('APPROVE', 'Loans', "Approved bulk deduction batch ({$batchKey}) for {$updated} employee(s)");

            $fresh = Loan::with('employee', 'approver')
                ->where('batch_key', $batchKey)
                ->orderBy('employee_id')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => $updated > 0
                    ? "Bulk deduction approved for {$updated} employee(s)"
                    : 'No pending deductions found in this batch (already approved or rejected)',
                'data' => $fresh,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve bulk deduction: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function bulkReject(Request $request, $batchKey)
        {
        try {
            $request->validate([
                'rejection_reason' => 'required|string|max:500',
            ]);

            $loans = Loan::where('batch_key', $batchKey)->get();

            if ($loans->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bulk deduction batch not found',
                ], 404);
            }

            $updated = Loan::where('batch_key', $batchKey)
                ->where('approval_status', 'pending')
                ->update([
                    'approval_status' => 'rejected',
                    'rejection_reason' => $request->rejection_reason,
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                ]);

            $this->logActivity('REJECT', 'Loans', "Rejected bulk deduction batch ({$batchKey}) for {$updated} employee(s). Reason: {$request->rejection_reason}");

            $fresh = Loan::with('employee', 'approver')
                ->where('batch_key', $batchKey)
                ->orderBy('employee_id')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => $updated > 0
                    ? "Bulk deduction rejected for {$updated} employee(s)"
                    : 'No pending deductions found in this batch (already approved or rejected)',
                'data' => $fresh,
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
                'message' => 'Failed to reject bulk deduction: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function bulkUpdate(Request $request, $batchKey)
    {
        try {
            $request->validate([
                'loan_type' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:500',
                'total_amount' => 'nullable|numeric|min:0',
                'monthly_installment' => 'nullable|numeric|min:0',
                'effective_from' => 'nullable|string|date_format:Y-m',
                'effective_to' => 'nullable|string|date_format:Y-m',
                'status' => 'nullable|in:active,completed,cancelled',
            ]);

            $loans = Loan::where('batch_key', $batchKey)->get();
            if ($loans->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bulk deduction batch not found',
                ], 404);
            }

            $data = $request->only([
                'loan_type', 'description', 'total_amount',
                'monthly_installment', 'effective_from', 'effective_to', 'status',
            ]);

            if (isset($data['effective_from'])) {
                $data['start_date'] = Carbon::createFromFormat('Y-m', $data['effective_from'])->startOfMonth()->toDateString();
            }
            if ($request->has('effective_to')) {
                $data['end_date'] = $request->effective_to
                    ? Carbon::createFromFormat('Y-m', $request->effective_to)->endOfMonth()->toDateString()
                    : null;
            }

            // If any loan in batch was approved, reset all to pending for re-approval
            $hasApproved = $loans->contains(fn ($l) => $l->approval_status === 'approved');
            if ($hasApproved) {
                $data['approval_status'] = 'pending';
                $data['rejection_reason'] = null;
                $data['approved_by'] = null;
                $data['approved_at'] = null;
            }

            Loan::where('batch_key', $batchKey)->update($data);

            // If total_amount changed, also reset remaining_amount
            if (isset($data['total_amount'])) {
                Loan::where('batch_key', $batchKey)->update(['remaining_amount' => $data['total_amount']]);
            }

            $fresh = Loan::with('employee', 'approver')->where('batch_key', $batchKey)->orderBy('employee_id')->get();

            $this->logActivity('UPDATE', 'Loans', "Bulk updated deduction batch ({$batchKey}) for " . $loans->count() . " employee(s)" . ($hasApproved ? '. Reset to pending approval.' : ''));

            return response()->json([
                'status' => 'success',
                'message' => $hasApproved
                    ? 'Bulk deduction updated and resubmitted for approval'
                    : 'Bulk deduction updated successfully',
                'data' => $fresh,
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
                'message' => 'Failed to update bulk deduction: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function bulkDestroy($batchKey)
    {
        try {
            $loans = Loan::where('batch_key', $batchKey)->get();
            if ($loans->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bulk deduction batch not found',
                ], 404);
            }
            $count = $loans->count();
            $ids = $loans->pluck('id')->toArray();
            Loan::where('batch_key', $batchKey)->delete();

            $this->logActivity('DELETE', 'Loans', "Deleted bulk deduction batch ({$batchKey}) for {$count} employee(s). IDs: " . implode(',', $ids));

            return response()->json([
                'status' => 'success',
                'message' => "Bulk deduction deleted for {$count} employee(s)",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete bulk deduction: ' . $e->getMessage(),
            ], 500);
        }
    }
}
