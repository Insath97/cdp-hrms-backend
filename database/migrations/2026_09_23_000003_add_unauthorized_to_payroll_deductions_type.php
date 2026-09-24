<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `payroll_deductions` MODIFY COLUMN `type` ENUM(
            'epf_employee',
            'loan',
            'advance',
            'absent',
            'late',
            'tax',
            'penalty',
            'other',
            'policy_cancellation',
            'unauthorized'
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
            'other',
            'policy_cancellation'
        ) NOT NULL");
    }
};