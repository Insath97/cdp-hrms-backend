<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveEntitlementRule;
use App\Models\LeaveType;
use Carbon\Carbon;

class LeaveEntitlementService
{
    /**
     * Calculate entitlement (allocated/available by policy) for a policy-driven leave type in a given year.
     * For casual year 1 (monthly_on_completion), this returns amount accrued up to $asOf (capped).
     */
    public function calculateEntitlement(Employee $employee, LeaveType $leaveType, int $year, ?Carbon $asOf = null): array
    {
        if ($asOf) {
            $asOf = $asOf->copy();
        } else {
            $currentYear = (int) now()->year;
            if ($year === $currentYear) {
                $asOf = now();
            } elseif ($year < $currentYear) {
                $asOf = Carbon::create($year, 12, 31);
            } else {
                $asOf = Carbon::create($year, 1, 1)->startOfDay();
            }
        }

        $joinedAt = $this->resolveJoinedAt($employee);
        if (!$joinedAt || !$leaveType->isPolicyDriven()) {
            $days = (float) $leaveType->default_allocation;
            return ['entitled' => $days, 'allocated' => $days, 'accrual_type' => 'grant'];
        }

        $joinYear = (int) $joinedAt->year;
        if ($year < $joinYear) {
            return ['entitled' => 0.0, 'allocated' => 0.0, 'accrual_type' => 'grant'];
        }

        $serviceYear = $year - $joinYear + 1;
        $rule = $this->findRule($leaveType->category, $serviceYear, (int) $joinedAt->month);
        if (!$rule) {
            return ['entitled' => 0.0, 'allocated' => 0.0, 'accrual_type' => 'grant'];
        }

        $entitled = (float) $rule->entitlement_days;
        $allocated = $entitled;

        if ($rule->accrual_type === 'monthly_on_completion') {
            $entitled = (float) ($rule->max_days ?? $rule->entitlement_days * 12);
            $allocated = $this->accruedMonths($joinedAt, $year, $asOf) * (float) $rule->entitlement_days;
            $allocated = min($allocated, $entitled);
        }

        return [
            'entitled' => round($entitled, 2),
            'allocated' => round($allocated, 2),
            'accrual_type' => $rule->accrual_type,
        ];
    }

    public function syncBalance(Employee $employee, LeaveType $leaveType, int $year, ?Carbon $asOf = null): ?LeaveBalance
    {
        $userId = $employee->user?->id;
        $calc = $leaveType->isPolicyDriven()
            ? $this->calculateEntitlement($employee, $leaveType, $year, $asOf)
            : ['entitled' => (float) $leaveType->default_allocation, 'allocated' => (float) $leaveType->default_allocation, 'accrual_type' => 'grant'];

        $balance = LeaveBalance::firstOrNew([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => $year,
        ]);

        $balance->user_id = $userId;
        $balance->entitled = $calc['entitled'];
        $balance->allocated = $calc['allocated'];
        $balance->used = $balance->exists ? (float) $balance->used : 0.0;
        $balance->balance = round((float) $balance->allocated - (float) $balance->used, 2);
        if ($calc['accrual_type'] === 'monthly_on_completion') {
            $balance->last_accrued_at = ($asOf ?: now())->toDateString();
        }
        $balance->save();

        return $balance;
    }

    public function syncEmployeeYear(Employee $employee, int $year, ?Carbon $asOf = null): void
    {
        $types = LeaveType::where('is_active', true)->get();
        foreach ($types as $type) {
            $this->syncBalance($employee, $type, $year, $asOf);
        }
    }

    protected function findRule(string $category, int $serviceYear, int $joinMonth): ?LeaveEntitlementRule
    {
        return LeaveEntitlementRule::where('category', $category)
            ->where('is_active', true)
            ->where('service_year_from', '<=', $serviceYear)
            ->where(function ($q) use ($serviceYear) {
                $q->whereNull('service_year_to')->orWhere('service_year_to', '>=', $serviceYear);
            })
            ->where(function ($q) use ($joinMonth) {
                $q->whereNull('join_month_from')
                    ->orWhere(function ($q2) use ($joinMonth) {
                        $q2->where('join_month_from', '<=', $joinMonth)
                           ->where('join_month_to', '>=', $joinMonth);
                    });
            })
            ->orderByDesc('service_year_from')
            ->first();
    }

    protected function accruedMonths(Carbon $joinedAt, int $year, Carbon $asOf): int
    {
        $joinYear = (int) $joinedAt->year;
        if ($year < $joinYear) return 0;
        $startMonth = ($year === $joinYear) ? (int) $joinedAt->month : 1;
        $endMonth = 12;
        if ($asOf->year == $year) {
            $endMonth = (int) $asOf->month;
        } elseif ($asOf->year < $year) {
            return 0;
        }
        // completed months: if in join month/year, month completes at end of that month; count months from startMonth..endMonth-? 
        // A month is completed when we've passed the month-end of that month relative to asOf.
        $count = 0;
        for ($m = $startMonth; $m <= 12; $m++) {
            $monthEnd = Carbon::create($year, $m, 1)->endOfMonth();
            if ($asOf->greaterThanOrEqualTo($monthEnd) && $m <= $endMonth) {
                $count++;
            }
        }
        return max(0, min(12, $count));
    }

    protected function resolveJoinedAt(Employee $employee): ?Carbon
    {
        $value = $employee->getRawOriginal('joined_at') ?? $employee->joined_at;
        if (!$value) return null;
        try { return Carbon::parse($value)->startOfDay(); } catch (\Throwable $e) { return null; }
    }
}
