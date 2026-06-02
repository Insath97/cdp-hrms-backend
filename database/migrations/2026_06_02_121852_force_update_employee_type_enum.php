<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Force update the ENUM by temporarily converting to VARCHAR and back
        DB::statement("ALTER TABLE employees MODIFY employee_type VARCHAR(50)");

        // Now change it back to ENUM with the new values
        DB::statement("ALTER TABLE employees MODIFY employee_type ENUM('permanent', 'contract', 'internship', 'probation', 'non_permanent', 'solo') DEFAULT 'permanent'");
    }

    public function down(): void
    {
        // Revert to original ENUM
        DB::statement("ALTER TABLE employees MODIFY employee_type VARCHAR(50)");
        DB::statement("ALTER TABLE employees MODIFY employee_type ENUM('permanent', 'contract', 'internship', 'probation') DEFAULT 'permanent'");
    }
};
