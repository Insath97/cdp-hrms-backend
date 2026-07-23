<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_record_id')->constrained()->onDelete('cascade');
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('travel_reimbursement', 12, 2)->default(0);
            $table->decimal('vehicle_rental', 12, 2)->default(0);
            $table->decimal('performance_allowance', 12, 2)->default(0);
            $table->decimal('incentive', 12, 2)->default(0);
            $table->decimal('position_allowance', 12, 2)->default(0);
            $table->decimal('mobile_payment', 12, 2)->default(0);
            $table->decimal('monthly_target', 12, 2)->default(0);
            $table->decimal('total_package', 12, 2)->default(0);
            $table->decimal('achievement_percentage', 5, 2)->default(0);
            $table->decimal('payment_percentage', 5, 2)->default(0);
            $table->string('payment_criteria')->nullable();
            $table->decimal('calculated_payment', 12, 2)->default(0);
            $table->decimal('commission', 12, 2)->default(0);
            $table->decimal('override_commission', 12, 2)->default(0);
            $table->decimal('total_commission', 12, 2)->default(0);
            $table->decimal('total_epf_employee', 12, 2)->default(0);
            $table->decimal('total_epf_employer', 12, 2)->default(0);
            $table->decimal('total_etf_employer', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->timestamps();

            $table->unique('payroll_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_details');
    }
};
