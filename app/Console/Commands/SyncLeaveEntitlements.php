<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\LeaveEntitlementService;
use Illuminate\Console\Command;

class SyncLeaveEntitlements extends Command
{
    protected $signature = 'leave:sync-entitlements {--year=} {--employee=}';

    protected $description = 'Provision/sync leave allocations for employees based on entitlement rules (current year by default)';

    public function handle(LeaveEntitlementService $service): int
    {
        $year = (int) ($this->option('year') ?: date('Y'));
        $employeeId = $this->option('employee');

        $query = Employee::query();
        if ($employeeId) {
            $query->where('id', $employeeId);
        }

        $count = 0;
        $query->chunkById(100, function ($employees) use ($service, $year, &$count) {
            foreach ($employees as $employee) {
                $service->syncEmployeeYear($employee, $year);
                $count++;
            }
        });

        $this->info("Synced leave entitlements for {$count} employee(s) for year {$year}.");
        return Command::SUCCESS;
    }
}
