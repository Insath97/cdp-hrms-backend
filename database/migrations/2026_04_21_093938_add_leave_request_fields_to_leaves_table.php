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
        Schema::table('leaves', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropForeignKeyIfExists(['employee_id']);
            $table->dropForeignKeyIfExists(['leave_type_id']);
            $table->dropForeignKeyIfExists(['approved_by']);
            $table->dropForeignKeyIfExists(['rejected_by']);
            $table->dropColumn(['employee_id', 'leave_type_id', 'from_date', 'to_date', 'reason', 'status', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'reject_reason']);
        });
    }
};
