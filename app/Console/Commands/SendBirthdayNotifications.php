<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\BirthdayNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendBirthdayNotifications extends Command
{
    protected $signature = 'notifications:birthdays {date? : Date in Y-m-d format (defaults to today)}';

    protected $description = 'Send birthday notifications for employees whose birthday is today';

    public function handle(): int
    {
        $date = $this->argument('date') ?? Carbon::today()->toDateString();
        $carbonDate = Carbon::parse($date);

        // Birthday notifications are only relevant on the day itself. Remove
        // older ones so users who have not logged in for a few days do not
        // receive a backlog of stale birthday alerts.
        DB::table('notifications')
            ->where('data->type', 'birthday')
            ->where('created_at', '<', Carbon::today()->toDateTimeString())
            ->delete();

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
                // The command can run several times a day (for example when the
                // machine is switched on after 08:01). Never create a second
                // alert for the same employee on the same day.
                $alreadySent = DB::table('notifications')
                    ->where('notifiable_type', $notifiableUser->getMorphClass())
                    ->where('notifiable_id', $notifiableUser->id)
                    ->where('data->type', 'birthday')
                    ->where('data->employee_id', $employee->id)
                    ->where('created_at', '>=', Carbon::today()->toDateTimeString())
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

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
