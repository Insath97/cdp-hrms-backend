<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Clear orphaned child rows
        DB::table('payroll_deductions')->truncate();

        Schema::dropIfExists('payroll_records');

        Schema::create('payroll_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('employee_id')->nullable()->constrained()->onDelete('set null');
            $table->string('month', 20);

            // Salary components
            $table->decimal('basic', 12, 2)->default(0);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('travel_reimbursement', 12, 2)->default(0);
            $table->decimal('vehicle_rental', 12, 2)->default(0);
            $table->decimal('performance_allowance', 12, 2)->default(0);
            $table->decimal('incentive', 12, 2)->default(0);
            $table->decimal('position_allowance', 12, 2)->default(0);
            $table->decimal('mobile_payment', 12, 2)->default(0);
            $table->decimal('monthly_target', 12, 2)->default(0);
            $table->decimal('total_package', 12, 2)->default(0);

            // Performance
            $table->decimal('achievement_percentage', 5, 2)->default(0);
            $table->decimal('payment_percentage', 5, 2)->default(0);
            $table->string('payment_criteria')->nullable();

            // Earnings
            $table->decimal('calculated_payment', 12, 2)->default(0);
            $table->decimal('mobile_payment_bonus', 12, 2)->default(0);
            $table->decimal('commission', 12, 2)->default(0);
            $table->decimal('override_commission', 12, 2)->default(0);
            $table->decimal('total_commission', 12, 2)->default(0);
            $table->decimal('how_much_paid', 12, 2)->default(0);

            // Deductions breakdown
            $table->decimal('epf_employee', 12, 2)->default(0);
            $table->decimal('epf_employer', 12, 2)->default(0);
            $table->decimal('etf_employer', 12, 2)->default(0);
            $table->decimal('paye_tax', 12, 2)->default(0);
            $table->decimal('recover_amount', 12, 2)->default(0);
            $table->decimal('loan_deductions', 12, 2)->default(0);
            $table->decimal('advance_deductions', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);

            // Net pay
            $table->decimal('net', 12, 2)->default(0);

            // Status
            $table->enum('status', ['draft', 'pending', 'processed']);
            $table->string('file_path')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'month']);
            $table->unique(['user_id', 'month']);
        });

        // Re-add FKs
        Schema::table('payroll_deductions', function (Blueprint $table) {
            $table->foreign('payroll_record_id')->references('id')->on('payroll_records')->onDelete('cascade');
        });
        Schema::table('payslip_requests', function (Blueprint $table) {
            $table->foreign('payroll_record_id')->references('id')->on('payroll_records')->onDelete('set null');
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        Schema::dropIfExists('payroll_records');

        Schema::create('payroll_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('month', 20);
            $table->decimal('basic', 12, 2);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('net', 12, 2);
            $table->decimal('epf_employee', 12, 2);
            $table->decimal('epf_employer', 12, 2);
            $table->decimal('etf_employer', 12, 2);
            $table->enum('status', ['draft', 'pending', 'processed']);
            $table->string('file_path')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'month']);
            $table->unique(['user_id', 'month']);
        });

        Schema::table('payroll_deductions', function (Blueprint $table) {
            $table->foreign('payroll_record_id')->references('id')->on('payroll_records')->onDelete('cascade');
        });
        Schema::table('payslip_requests', function (Blueprint $table) {
            $table->foreign('payroll_record_id')->references('id')->on('payroll_records')->onDelete('set null');
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
