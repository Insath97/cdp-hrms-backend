<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class CreateRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'roles:create';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Admin and Staff roles';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Create Admin role
            $adminRole = Role::firstOrCreate([
                'guard_name' => 'api',
                'name' => 'Admin'
            ]);
            $this->info('Admin role created or already exists');

            // Create Staff role
            $staffRole = Role::firstOrCreate([
                'guard_name' => 'api',
                'name' => 'Staff'
            ]);
            $this->info('Staff role created or already exists');

            $this->info('Roles created successfully!');
        } catch (\Exception $e) {
            $this->error('Error creating roles: ' . $e->getMessage());
        }
    }
}
