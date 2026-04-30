<?php
// app/Http/Controllers/V1/PayrollController.php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\PayrollRecord;
use App\Models\PayslipRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PayrollController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Payroll View', only: ['index']),
            new Middleware('permission:Payroll Request', only: ['requestPayslip']),
            new Middleware('permission:Payroll Print', only: ['printPayslip']),
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
                'statutory' => $statutory
            ]);
            
        } catch (\Throwable $th) {
            \Log::error('Failed to retrieve payroll records', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve payroll records',
                'error' => $th->getMessage()
            ], 500);
        }
    }
    
    public function requestPayslip(Request $request, $payrollRecordId)
    {
        try {
            $request->validate([
                'reason' => 'nullable|string|max:500'
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
                        'message' => 'A ' . $existingRequest->status . ' request already exists for this payslip'
                    ], 400);
                }
                
                // If request was rejected, update it to pending (re-request)
                if ($existingRequest->status === 'rejected') {
                    $existingRequest->update([
                        'status' => 'pending',
                        'reason' => $request->reason,
                        'rejection_reason' => null, // Clear the rejection reason
                        'updated_at' => now()
                    ]);
                    
                    \Log::info('Payslip request re-submitted', [
                        'user_id' => Auth::id(),
                        'payroll_record_id' => $payrollRecord->id,
                        'request_id' => $existingRequest->id,
                        'previous_status' => 'rejected'
                    ]);
                    
                    return response()->json([
                        'status' => 'success',
                        'message' => 'Request re-submitted successfully',
                        'request' => $existingRequest
                    ]);
                }
            }
            
            // Create new request if none exists
            $payslipRequest = PayslipRequest::create([
                'user_id' => Auth::id(),
                'payroll_record_id' => $payrollRecord->id,
                'status' => 'pending',
                'reason' => $request->reason
            ]);
            
            \Log::info('Payslip request submitted', [
                'user_id' => Auth::id(),
                'payroll_record_id' => $payrollRecord->id,
                'request_id' => $payslipRequest->id
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Request submitted successfully',
                'request' => $payslipRequest
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to submit payslip request', [
                'user_id' => Auth::id(),
                'payroll_record_id' => $payrollRecordId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit request: ' . $e->getMessage()
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
                'can_print' => $request && $request->status === 'approved' && $request->signed_file_path
            ]);
            
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get request status',
                'error' => $th->getMessage()
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
                
            if (!$approvedRequest) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No approved request found for this payslip'
                ], 403);
            }
            
            // Get the file path from the database
            $filePath = $approvedRequest->signed_file_path;
            
            // Try to get the file from storage
            if (Storage::disk('public')->exists($filePath)) {
                $file = Storage::disk('public')->get($filePath);
                $filename = "payslip_{$payrollRecord->month}_{$user->name}.pdf";
                
                return response($file, 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            }
            
            // Alternative: Check full storage path
            $fullPath = storage_path('app/public/' . $filePath);
            if (file_exists($fullPath)) {
                return response()->download($fullPath, "payslip_{$payrollRecord->month}_{$user->name}.pdf");
            }
            
            // If file not found, log error
            \Log::error('Payslip file not found', [
                'file_path' => $filePath,
                'user_id' => $user->id,
                'request_id' => $approvedRequest->id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Payslip file not found. Please contact HR.'
            ], 404);
            
        } catch (\Exception $e) {
            \Log::error('Failed to print payslip', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to download payslip: ' . $e->getMessage()
            ], 500);
        }
    }
}