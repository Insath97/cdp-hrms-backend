<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslip_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('payroll_record_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['pending', 'approved', 'rejected']);
            $table->text('reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->string('signed_file_path')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'payroll_record_id']);
            $table->unique(['user_id', 'payroll_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_requests');
    }
};