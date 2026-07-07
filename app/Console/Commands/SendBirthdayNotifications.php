<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\BirthdayNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendBirthdayNotifications extends Command
{
    protected $signature = 'notifications:birthdays {date? : Date in Y-m-d format (defaults to today)}';

    protected $description = 'Send birthday notifications for employees whose birthday is today';

    public function handle(): int
    {
        $date = $this->argument('date') ?? Carbon::today()->toDateString();
        $carbonDate = Carbon::parse($date);

        $this->info("Checking birthdays for: {$date}");

        $birthdayEmployees = Employee::whereMonth('date_of_birth', $carbonDate->month)
            ->whereDay('date_of_birth', $carbonDate->day)
            ->where('is_active', true)
            ->get();

        if ($birthdayEmployees->isEmpty()) {
            $this->info('No birthdays found for this date.');
            return Command::SUCCESS;
        }

        $this->info("Found {$birthdayEmployees->count()} birthday(s).");

        $sentCount = 0;

        foreach ($birthdayEmployees as $employee) {
            // Get all active users except the birthday employee themselves (include admin/users with null employee_id)
            $allUsers = User::where('is_active', true)
                ->where(function ($query) use ($employee) {
                    $query->where('employee_id', '!=', $employee->id)
                          ->orWhereNull('employee_id');
                })
                ->get();

            foreach ($allUsers as $notifiableUser) {
                $notifiableUser->notify(new BirthdayNotification($employee));
                $sentCount++;
            }

            $this->line("  ✓ Birthday notification for {$employee->full_name} sent to {$allUsers->count()} user(s).");
        }

        $this->info("Done. Sent {$sentCount} birthday notification(s).");

        Log::info('notifications:birthdays command completed', [
            'date' => $date,
            'birthdays_found' => $birthdayEmployees->count(),
            'sent' => $sentCount,
        ]);

        return Command::SUCCESS;
    }
}
