<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('payroll_record_id')->constrained()->onDelete('set null');
            $table->decimal('mobile_payment_bonus', 12, 2)->default(0)->after('calculated_payment');
            $table->decimal('recover_amount', 12, 2)->default(0)->after('total_commission');
            $table->decimal('income_tax', 12, 2)->default(0)->after('recover_amount');
            $table->decimal('how_much_paid', 12, 2)->default(0)->after('income_tax');
            $table->string('employee_type', 30)->nullable()->after('how_much_paid');
            $table->string('employee_code', 30)->nullable()->after('employee_type');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn([
                'employee_id', 'mobile_payment_bonus', 'recover_amount',
                'income_tax', 'how_much_paid', 'employee_type', 'employee_code',
            ]);
        });
    }
};
