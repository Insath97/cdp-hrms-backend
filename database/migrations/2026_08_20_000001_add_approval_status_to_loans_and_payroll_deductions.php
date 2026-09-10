<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── loans table ──────────────────────────────────────────────
        Schema::table('loans', function (Blueprint $table) {
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending')->after('status');
            $table->text('rejection_reason')->nullable()->after('approval_status');
            $table->foreignId('approved_by')->nullable()->after('rejection_reason')->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        // Grandfather existing active loans as approved
        DB::table('loans')->where('status', 'active')->update(['approval_status' => 'approved']);

        // ── payroll_deductions table ─────────────────────────────────
        Schema::table('payroll_deductions', function (Blueprint $table) {
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('approved')->after('is_auto');
            $table->text('rejection_reason')->nullable()->after('approval_status');
            $table->foreignId('approved_by')->nullable()->after('rejection_reason')->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        // Grandfather all existing payroll deductions as approved
        DB::table('payroll_deductions')->update(['approval_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'rejection_reason', 'approved_by', 'approved_at']);
        });

        Schema::table('payroll_deductions', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'rejection_reason', 'approved_by', 'approved_at']);
        });
    }
};
