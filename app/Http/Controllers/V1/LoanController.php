<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Traits\ActivityLogTrait;

class LoanController extends Controller
{
    use ActivityLogTrait;

    public function index(Request $request)
    {
        try {
            $query = Loan::with('employee');

            if ($request->has('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->whereHas('employee', function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('employee_code', 'like', "%{$search}%");
                });
            }

            $perPage = $request->get('per_page', 15);
            $loans = $query->orderBy('created_at', 'desc')->paginate($perPage);

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

    public function store(Request $request)
    {
        try {
            $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'loan_type' => 'required|string|max:100',
                'description' => 'nullable|string|max:500',
                'total_amount' => 'required|numeric|min:0',
                'monthly_installment' => 'required|numeric|min:0',
                'start_date' => 'required|date',
                'end_date' => 'nullable|date|after:start_date',
            ]);

            $loan = Loan::create([
                'employee_id' => $request->employee_id,
                'loan_type' => $request->loan_type,
                'description' => $request->description,
                'total_amount' => $request->total_amount,
                'monthly_installment' => $request->monthly_installment,
                'remaining_amount' => $request->total_amount,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'status' => 'active',
            ]);

            $loan->load('employee');

            $this->logActivity('CREATE', 'Loans', "Created loan (ID: {$loan->id}) for employee ID: {$loan->employee_id}. Amount: {$loan->total_amount}");

            return response()->json([
                'status' => 'success',
                'message' => 'Loan created successfully',
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

    public function show($id)
    {
        try {
            $loan = Loan::with('employee', 'deductions')->findOrFail($id);

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
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'status' => 'nullable|in:active,completed,cancelled',
            ]);

            $loan->update($request->only([
                'loan_type', 'description', 'total_amount',
                'monthly_installment', 'start_date', 'end_date', 'status',
            ]));

            $loan->load('employee');

            $this->logActivity('UPDATE', 'Loans', "Updated loan (ID: {$loan->id}) for employee ID: {$loan->employee_id}");

            return response()->json([
                'status' => 'success',
                'message' => 'Loan updated successfully',
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
}
