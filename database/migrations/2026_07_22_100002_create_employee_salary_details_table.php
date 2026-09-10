<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_details');
    }
};
