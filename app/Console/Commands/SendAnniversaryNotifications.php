<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\AnniversaryNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendAnniversaryNotifications extends Command
{
    protected $signature = 'notifications:anniversaries {date? : Date in Y-m-d format (defaults to today)}';

    protected $description = 'Send work anniversary notifications to employees whose joining anniversary falls today';

    public function handle(): int
    {
        $date = $this->argument('date') ?? Carbon::today()->toDateString();
        $carbonDate = Carbon::parse($date);

        $this->info("Checking work anniversaries for: {$date}");

        $employees = Employee::whereNotNull('joined_at')
            ->whereMonth('joined_at', $carbonDate->month)
            ->whereDay('joined_at', $carbonDate->day)
            ->where('is_active', true)
            ->get();

        if ($employees->isEmpty()) {
            $this->info('No work anniversaries found for this date.');
            return Command::SUCCESS;
        }

        $sentCount = 0;

        foreach ($employees as $employee) {
            $years = (int) Carbon::parse($employee->joined_at)->diffInYears($carbonDate);

            // Only meaningful from the first full year onward.
            if ($years < 1) {
                continue;
            }

            // A work anniversary is personal: only notify the employee themselves.
            $employeeUsers = User::where('employee_id', $employee->id)
                ->where('is_active', true)
                ->get();

            if ($employeeUsers->isEmpty()) {
                $this->line("  - No active user account for {$employee->full_name}; skipped.");
                continue;
            }

            foreach ($employeeUsers as $notifiableUser) {
                $notifiableUser->notify(new AnniversaryNotification($employee, $years));
                $sentCount++;
            }

            $this->line("  ✓ {$years}-year anniversary notification for {$employee->full_name} sent to {$employeeUsers->count()} user(s).");
        }

        $this->info("Done. Sent {$sentCount} anniversary notification(s).");

        Log::info('notifications:anniversaries command completed', [
            'date' => $date,
            'anniversaries_found' => $employees->count(),
            'sent' => $sentCount,
        ]);

        return Command::SUCCESS;
    }
}
