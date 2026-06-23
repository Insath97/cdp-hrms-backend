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
        Schema::table('payslip_requests', function (Blueprint $table) {
            // Drop old unique constraint
            $table->dropUnique('payslip_requests_user_id_payroll_record_id_unique');
            
            // Make payroll_record_id nullable
            $table->unsignedBigInteger('payroll_record_id')->nullable()->change();
            
            // Add period field
            $table->string('period', 7)->nullable()->after('payroll_record_id'); // YYYY-MM
            
            // Add new unique constraint
            $table->unique(['user_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payslip_requests', function (Blueprint $table) {
            $table->dropUnique('payslip_requests_user_id_period_unique');
            $table->dropColumn('period');
            $table->unsignedBigInteger('payroll_record_id')->nullable(false)->change();
            $table->unique(['user_id', 'payroll_record_id']);
        });
    }
};
