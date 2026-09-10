<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_record_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['epf_employee', 'loan', 'advance', 'absent', 'late', 'tax', 'penalty', 'other']);
            $table->string('label');
            $table->decimal('amount', 12, 2);
            $table->foreignId('loan_id')->nullable()->constrained()->onDelete('set null');
            $table->boolean('is_auto')->default(false); // true = auto-populated, false = admin-added
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_deductions');
    }
};
