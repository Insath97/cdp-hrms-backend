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
        Schema::create('letters', function (Blueprint $table) {
            $table->id();
            $table->string('ref_number')->unique();
            $table->string('title');
            $table->string('employee_name');
            $table->string('nic_number');
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city');
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('designation_id')->constrained('designations')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('date');
            $table->string('signed_by_name');
            $table->string('signed_by_designation');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('letters');
    }
};
