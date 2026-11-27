<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\LeaveEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveBalanceController extends Controller
{
    /**
     * Display a listing of leave balances for the authenticated user or a specific user.
     * Syncs policy-driven balances on read to reflect current accrual/allocation rules.
     */
    public function index(Request $request, LeaveEntitlementService $entitlementService)
    {
        try {
            $userId = $request->input('user_id', Auth::id());
            $employeeId = $request->input('employee_id');
            $year = (int) $request->input('year', date('Y'));
            $month = (int) $request->input('month', date('n'));

            if (!$employeeId && $userId) {
                $employeeId = optional(\App\Models\User::find($userId))->employee_id;
            }

            if ($employeeId) {
                $employee = Employee::with('user')->find($employeeId);
                if ($employee) {
                    $entitlementService->syncEmployeeYear($employee, $year);
                }
                $query = LeaveBalance::with('leaveType', 'user', 'employee')
                    ->where('year', $year)
                    ->where('employee_id', $employeeId);
            } else {
                $query = LeaveBalance::with('leaveType', 'user', 'employee')
                    ->where('year', $year)
                    ->where('user_id', $userId);
            }

            $balances = $query->get()->map(function ($balance) use ($month, $year) {
                $leaveType = $balance->leaveType;
                $isShortLeave = $leaveType && (
                    strtoupper((string) $leaveType->code) === 'SL' ||
                    stripos((string) $leaveType->name, 'short') !== false
                );

                if ($isShortLeave) {
                    $countQuery = \App\Models\Leave::whereIn('status', ['pending', 'approved'])
                        ->whereYear('from_date', $year)
                        ->whereMonth('from_date', $month)
                        ->whereHas('leaveType', function ($q) {
                            $q->whereRaw("UPPER(code) = 'SL'")
                              ->orWhere('name', 'like', '%short%');
                        });

                    if ($balance->employee_id) {
                        $countQuery->where('employee_id', $balance->employee_id);
                    } elseif ($balance->user_id) {
                        $countQuery->where('user_id', $balance->user_id);
                    } else {
                        $countQuery->whereRaw('1 = 0');
                    }

                    $usedThisMonth = (int) $countQuery->count();
                    $monthlyQuota = 2;
                    $balance->setAttribute('monthly_quota', $monthlyQuota);
                    $balance->setAttribute('used_this_month', $usedThisMonth);
                    $balance->setAttribute('remaining_this_month', max(0, $monthlyQuota - $usedThisMonth));
                    $balance->setAttribute('balance_month', $month);
                    $balance->setAttribute('balance_year', $year);
                }

                return $balance;
            });

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
