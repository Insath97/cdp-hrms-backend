<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveBalanceController extends Controller
{
    /**
     * Display a listing of leave balances for the authenticated user or a specific user.
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->input('user_id', Auth::id());
            $employeeId = $request->input('employee_id');
            $year = $request->input('year', date('Y'));

            $query = LeaveBalance::with('leaveType', 'user', 'employee')
                ->where('year', $year);

            if ($employeeId) {
                $query->where('employee_id', $employeeId);
            } else {
                $query->where('user_id', $userId);
            }

            $balances = $query->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Leave balances retrieved successfully',
                'data' => $balances
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve leave balances',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
