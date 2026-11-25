<?php

namespace App\Observers;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Services\LeaveEntitlementService;

class EmployeeObserver
{
    public function __construct(protected LeaveEntitlementService $entitlementService)
    {
    }

    /**
     * Provision leave balances when a new employee is added.
     */
    public function created(Employee $employee): void
    {
        $year = (int) now()->year;
        $employee->loadMissing('user');
        $this->entitlementService->syncEmployeeYear($employee, $year);
    }

    /**
     * Re-sync leave balances when join date changes.
     */
    public function updated(Employee $employee): void
    {
        if (!$employee->wasChanged('joined_at')) {
            return;
        }

        $employee->loadMissing('user');
        $years = LeaveBalance::where('employee_id', $employee->id)
            ->distinct()
            ->pluck('year')
            ->map(fn ($y) => (int) $y)
            ->all();

        $currentYear = (int) now()->year;
        if (!in_array($currentYear, $years, true)) {
            $years[] = $currentYear;
        }

        foreach ($years as $year) {
            $this->entitlementService->syncEmployeeYear($employee, $year);
        }
    }
}
