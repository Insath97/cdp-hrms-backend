<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add pay_day discriminator + new formula columns
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->enum('pay_day', ['basic', 'commission', 'allowance'])->default('basic')->after('month');
            $table->decimal('stamp_fee', 12, 2)->default(0)->after('total_deductions');
            $table->decimal('apiit_tax', 12, 2)->default(0)->after('paye_tax');
        });

        // Replace the (user_id, month) unique with (user_id, month, pay_day)
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'month']);
            $table->index(['pay_day']);
        });

        // Backfill: existing rows are treated as the 'basic' (5th) payment
        DB::statement('UPDATE payroll_records SET pay_day = "basic" WHERE pay_day = "" OR pay_day IS NULL');

        Schema::table('payroll_records', function (Blueprint $table) {
            $table->unique(['user_id', 'month', 'pay_day']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'month', 'pay_day']);
        });

        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropIndex(['pay_day']);
            $table->dropColumn(['pay_day', 'stamp_fee', 'apiit_tax']);
            $table->unique(['user_id', 'month']);
        });
    }
};