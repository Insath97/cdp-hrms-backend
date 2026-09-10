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

        Schema::dropIfExists('employee_salary_details');

        Schema::create('employee_salary_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->decimal('basic_salary', 12, 2)->nullable();
            $table->decimal('travel_reimbursement', 12, 2)->nullable();
            $table->decimal('vehicle_rental', 12, 2)->nullable();
            $table->decimal('performance_allowance', 12, 2)->nullable();
            $table->decimal('incentive', 12, 2)->nullable();
            $table->decimal('position_allowance', 12, 2)->nullable();
            $table->decimal('mobile_payment', 12, 2)->nullable();
            $table->decimal('monthly_target', 12, 2)->nullable();
            $table->decimal('total_package', 12, 2)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_details');

        Schema::create('employee_salary_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->decimal('monthly_target', 12, 2)->nullable();
            $table->decimal('basic_salary', 12, 2)->nullable();
            $table->decimal('travel_reimbursement', 12, 2)->nullable();
            $table->decimal('vehicle_rental', 12, 2)->nullable();
            $table->decimal('performance_allowance', 12, 2)->nullable();
            $table->decimal('incentive', 12, 2)->nullable();
            $table->decimal('position_allowance', 12, 2)->nullable();
            $table->decimal('mobile_payment', 12, 2)->nullable();
            $table->decimal('total_package', 12, 2)->nullable();
            $table->timestamps();
            $table->unique('employee_id');
        });
    }
};
