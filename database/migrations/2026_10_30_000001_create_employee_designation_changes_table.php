<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_designation_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->string('previous_designation_name')->nullable();
            $table->foreignId('new_designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->string('new_designation_name')->nullable();
            $table->string('change_type', 30)->default('promotion');
            $table->date('effective_date');
            $table->decimal('previous_total_package', 12, 2)->nullable();
            $table->decimal('new_total_package', 12, 2)->nullable();
            $table->decimal('salary_change_amount', 12, 2)->nullable();
            $table->decimal('salary_change_percentage', 8, 2)->nullable();
            $table->foreignId('previous_salary_detail_id')->nullable()->constrained('employee_salary_details')->nullOnDelete();
            $table->foreignId('new_salary_detail_id')->nullable()->constrained('employee_salary_details')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_designation_changes');
    }
};
