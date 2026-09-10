<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->decimal('how_much_paid', 12, 2)->default(0)->after('net');
            $table->decimal('mobile_payment_bonus', 12, 2)->default(0)->after('how_much_paid');
            $table->decimal('total_commission', 12, 2)->default(0)->after('mobile_payment_bonus');
            $table->decimal('income_tax', 12, 2)->default(0)->after('total_commission');
            $table->decimal('recover_amount', 12, 2)->default(0)->after('income_tax');
            $table->decimal('achievement_percentage', 5, 2)->default(0)->after('recover_amount');
            $table->decimal('payment_percentage', 5, 2)->default(0)->after('achievement_percentage');
            $table->string('payment_criteria')->nullable()->after('payment_percentage');
            $table->decimal('total_package', 12, 2)->default(0)->after('payment_criteria');
            $table->decimal('calculated_payment', 12, 2)->default(0)->after('total_package');
            $table->decimal('commission', 12, 2)->default(0)->after('calculated_payment');
            $table->decimal('override_commission', 12, 2)->default(0)->after('commission');
        });

        Schema::dropIfExists('payroll_details');
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropColumn([
                'how_much_paid', 'mobile_payment_bonus', 'total_commission',
                'income_tax', 'recover_amount', 'achievement_percentage',
                'payment_percentage', 'payment_criteria', 'total_package',
                'calculated_payment', 'commission', 'override_commission',
            ]);
        });

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
            $table->decimal('mobile_payment_bonus', 12, 2)->default(0);
            $table->decimal('commission', 12, 2)->default(0);
            $table->decimal('override_commission', 12, 2)->default(0);
            $table->decimal('total_commission', 12, 2)->default(0);
            $table->decimal('recover_amount', 12, 2)->default(0);
            $table->decimal('income_tax', 12, 2)->default(0);
            $table->decimal('how_much_paid', 12, 2)->default(0);
            $table->string('employee_type', 30)->nullable();
            $table->string('employee_code', 30)->nullable();
            $table->decimal('total_epf_employee', 12, 2)->default(0);
            $table->decimal('total_epf_employer', 12, 2)->default(0);
            $table->decimal('total_etf_employer', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->timestamps();
            $table->unique('payroll_record_id');
        });
    }
};
