<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
           DB::statement("ALTER TABLE employees MODIFY COLUMN employee_type ENUM('permanent', 'contract', 'internship', 'probation', 'non_permanent', 'solo') DEFAULT 'permanent'");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            DB::statement("ALTER TABLE employees MODIFY COLUMN employee_type ENUM('permanent', 'contract', 'internship', 'probation', 'non_permanent') DEFAULT 'permanent'");
        });
    }
};
