<?php

namespace App\Console\Commands;

use App\Services\AttendanceSessionExpiryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireOpenAttendanceSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:expire-sessions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Close attendance sessions that are still open after office end time + 1 hour';

    /**
     * Execute the console command.
     */
    public function handle(AttendanceSessionExpiryService $expiryService): int
    {
        $expired = $expiryService->expireStaleSessions();

        $this->info("Expired sessions: {$expired}");

        Log::info('attendance:expire-sessions command completed', [
            'expired' => $expired,
        ]);

        return Command::SUCCESS;
    }
}
