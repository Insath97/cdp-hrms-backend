<?php

namespace App\Console\Commands;

use App\Services\AttendanceSettingsService;
use Illuminate\Console\Command;

class InitializeAttendanceSettings extends Command
{
    protected $signature = 'attendance:init-settings';
    protected $description = 'Initialize attendance settings with default values';

    public function handle()
    {
        $this->info('Initializing attendance settings...');

        $service = new AttendanceSettingsService();

        // Force reload to create default settings
        $service->resetToDefaults();

        $this->info('Attendance settings initialized successfully!');

        $settings = $service->getAll();
        foreach ($settings as $key => $value) {
            $this->line("- {$key}: {$value}");
        }
    }
}
