<?php
// app/Services/LeaveBalanceService.php

namespace App\Services;

use App\Models\LeaveBalance;
use App\Models\Leave;
use App\Models\Employee;
use App\Models\User;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeaveBalanceService
{
    /**
     * Resolve employee ID from various inputs
     */
    public function resolveEmployeeId($identifier, $type = 'auto')
    {
        if ($type === 'user_id') {
            $user = User::find($identifier);
            return $user ? ($user->employee->id ?? null) : null;
        }

        if ($type === 'employee_id') {
            return $identifier;
        }

        // Auto detect
        $employee = Employee::find($identifier);
        if ($employee) {
            return $employee->id;
        }

        $user = User::find($identifier);
        if ($user && $user->employee) {
            return $user->employee->id;
        }

        return null;
    }

    /**
     * Resolve user ID from various inputs
     */
    public function resolveUserId($identifier, $type = 'auto')
    {
        if ($type === 'employee_id') {
            $employee = Employee::find($identifier);
            return $employee ? $employee->user_id : null;
        }

        if ($type === 'user_id') {
            return $identifier;
        }

        // Auto detect
        $user = User::find($identifier);
        if ($user) {
            return $user->id;
        }

        $employee = Employee::find($identifier);
        if ($employee && $employee->user) {
            return $employee->user->id;
        }

        return null;
    }

    /**
     * Get or create leave balance
     */
    public function getOrCreateBalance($identifier, $leaveTypeId, $year = null, $type = 'auto')
    {
        $year = $year ?? Carbon::now()->year;

        $userId = $this->resolveUserId($identifier, $type);
        $employeeId = $this->resolveEmployeeId($identifier, $type);

        if (!$userId && !$employeeId) {
            throw new \Exception("Unable to resolve user or employee from identifier: {$identifier}");
        }

        $balance = LeaveBalance::where(function($query) use ($userId, $employeeId) {
                if ($userId) {
                    $query->where('user_id', $userId);
                }
                if ($employeeId) {
                    $query->orWhere('employee_id', $employeeId);
                }
            })
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->first();

        if (!$balance) {
            $leaveType = LeaveType::find($leaveTypeId);
            $defaultAllocation = $leaveType ? $leaveType->default_allocation : 0;

            $balance = LeaveBalance::create([
                'user_id' => $userId,
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'year' => $year,
                'allocated' => $defaultAllocation,
                'used' => 0,
                'remaining' => $defaultAllocation,
                'pending' => 0
            ]);

            Log::info('Created leave balance', [
                'user_id' => $userId,
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'year' => $year
            ]);
        }

        return $balance;
    }

    /**
     * Check sufficient balance
     */
    public function hasSufficientBalance($identifier, $leaveTypeId, $days, $year = null, $type = 'auto')
    {
        $balance = $this->getOrCreateBalance($identifier, $leaveTypeId, $year, $type);
        return $balance->hasSufficientBalance($days);
    }

    /**
     * Get remaining balance
     */
    public function getRemainingBalance($identifier, $leaveTypeId, $year = null, $type = 'auto')
    {
        $balance = $this->getOrCreateBalance($identifier, $leaveTypeId, $year, $type);
        return $balance->remaining;
    }

    /**
     * Get balance summary
     */
    public function getBalanceSummary($identifier, $year = null, $type = 'auto')
    {
        $year = $year ?? Carbon::now()->year;

        $userId = $this->resolveUserId($identifier, $type);
        $employeeId = $this->resolveEmployeeId($identifier, $type);

        $balances = LeaveBalance::with('leaveType')
            ->where(function($query) use ($userId, $employeeId) {
                if ($userId) {
                    $query->where('user_id', $userId);
                }
                if ($employeeId) {
                    $query->orWhere('employee_id', $employeeId);
                }
            })
            ->where('year', $year)
            ->get();

        $summary = [];
        foreach ($balances as $balance) {
            $summary[] = [
                'balance_id' => $balance->id,
                'user_id' => $balance->user_id,
                'employee_id' => $balance->employee_id,
                'leave_type_id' => $balance->leave_type_id,
                'leave_type_name' => $balance->leaveType->name,
                'leave_type_code' => $balance->leaveType->code,
                'allocated' => $balance->allocated,
                'used' => $balance->used,
                'pending' => $balance->pending,
                'remaining' => $balance->remaining,
                'year' => $balance->year
            ];
        }

        return $summary;
    }

    /**
     * Deduct leave balance
     */
    public function deductLeaveBalance(Leave $leave)
    {
        if ($leave->status !== 'approved') {
            return false;
        }

        $days = $this->calculateLeaveDays($leave->from_date, $leave->to_date);
        $year = Carbon::parse($leave->from_date)->year;

        $identifier = $leave->employee_id ?? $leave->user_id;
        $balance = $this->getOrCreateBalance($identifier, $leave->leave_type_id, $year);

        DB::beginTransaction();
        try {
            if ($balance->pending > 0) {
                $pendingToRemove = min($days, $balance->pending);
                $balance->removeFromPending($pendingToRemove);
            }

            $balance->deductBalance($days);

            Log::info('Leave balance deducted', [
                'leave_id' => $leave->id,
                'days' => $days,
                'remaining' => $balance->remaining
            ]);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to deduct leave balance', [
                'leave_id' => $leave->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Add to pending balance
     */
    public function addToPendingBalance(Leave $leave)
    {
        $days = $this->calculateLeaveDays($leave->from_date, $leave->to_date);
        $year = Carbon::parse($leave->from_date)->year;

        $identifier = $leave->employee_id ?? $leave->user_id;
        $balance = $this->getOrCreateBalance($identifier, $leave->leave_type_id, $year);
        $balance->addToPending($days);

        Log::info('Added to pending balance', [
            'leave_id' => $leave->id,
            'days' => $days,
            'total_pending' => $balance->pending
        ]);

        return $balance;
    }

    /**
     * Calculate leave days
     */
    public function calculateLeaveDays($fromDate, $toDate, $excludeWeekends = false)
    {
        $start = Carbon::parse($fromDate);
        $end = Carbon::parse($toDate);
        $days = $start->diffInDays($end) + 1;

        if ($excludeWeekends) {
            $workingDays = 0;
            for ($date = $start; $date <= $end; $date->addDay()) {
                if (!$date->isWeekend()) {
                    $workingDays++;
                }
            }
            return $workingDays;
        }

        return $days;
    }

    /**
     * Initialize balances for all employees
     */
    public function initializeBalancesForLeaveType($leaveTypeId, $year = null)
    {
        $year = $year ?? Carbon::now()->year;
        $leaveType = LeaveType::find($leaveTypeId);

        if (!$leaveType) {
            return false;
        }

        $users = User::whereHas('employee')->with('employee')->get();
        $created = 0;

        foreach ($users as $user) {
            $balance = LeaveBalance::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'employee_id' => $user->employee->id,
                    'leave_type_id' => $leaveTypeId,
                    'year' => $year
                ],
                [
                    'allocated' => $leaveType->default_allocation,
                    'used' => 0,
                    'remaining' => $leaveType->default_allocation,
                    'pending' => 0
                ]
            );

            if ($balance->wasRecentlyCreated) {
                $created++;
            }
        }

        // Users without employees
        $usersWithoutEmployees = User::whereDoesntHave('employee')->get();
        foreach ($usersWithoutEmployees as $user) {
            $balance = LeaveBalance::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'employee_id' => null,
                    'leave_type_id' => $leaveTypeId,
                    'year' => $year
                ],
                [
                    'allocated' => $leaveType->default_allocation,
                    'used' => 0,
                    'remaining' => $leaveType->default_allocation,
                    'pending' => 0
                ]
            );

            if ($balance->wasRecentlyCreated) {
                $created++;
            }
        }

        Log::info('Initialized leave balances', [
            'leave_type_id' => $leaveTypeId,
            'year' => $year,
            'created' => $created
        ]);

        return $created;
    }
}
