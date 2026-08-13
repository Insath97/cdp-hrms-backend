<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL doesn't support ALTER COLUMN for enums directly,
        // so we use DB statement to modify the enum
        DB::statement("ALTER TABLE `payroll_deductions` MODIFY COLUMN `type` ENUM(
            'epf_employee',
            'loan',
            'advance',
            'absent',
            'late',
            'tax',
            'penalty',
            'other',
            'policy_cancellation'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `payroll_deductions` MODIFY COLUMN `type` ENUM(
            'epf_employee',
            'loan',
            'advance',
            'absent',
            'late',
            'tax',
            'penalty',
            'other'
        ) NOT NULL");
    }
};
