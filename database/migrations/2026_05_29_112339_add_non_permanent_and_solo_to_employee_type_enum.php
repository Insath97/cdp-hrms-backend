<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE employees MODIFY COLUMN employee_type ENUM('permanent', 'contract', 'internship', 'probation', 'non_permanent', 'solo') NOT NULL DEFAULT 'permanent'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_type_enum', function (Blueprint $table) {
            //
        });
    }
};
