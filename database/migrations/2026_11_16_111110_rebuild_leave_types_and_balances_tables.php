<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reset leave history as requested
        Schema::disableForeignKeyConstraints();
        DB::table('leaves')->truncate();

        // Drop leave_balances
        Schema::dropIfExists('leave_balances');

        // Archive and replace leave_types (after detaching leaves FK)
        if (Schema::hasTable('leaves') && Schema::hasColumn('leaves', 'leave_type_id')) {
            try {
                Schema::table('leaves', function (Blueprint $table) {
                    $table->dropForeign(['leave_type_id']);
                });
            } catch (\Throwable $e) {
                // FK name may differ; ignore
            }
        }

        // Drop leave_types
        Schema::dropIfExists('leave_types');

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('category')->default('other'); // annual, casual, other
            $table->enum('calculation_unit', ['days', 'hours'])->default('days');
            $table->decimal('default_allocation', 10, 2)->default(0);
            $table->string('color_code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_pregnancy_related')->default(false);
            $table->integer('pregnancy_weeks_required')->nullable();
            $table->integer('pre_delivery_weeks')->nullable();
            $table->integer('post_delivery_weeks')->nullable();
            $table->boolean('requires_medical_certificate')->default(false);
            $table->timestamps();
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->decimal('entitled', 8, 2)->default(0);
            $table->decimal('allocated', 8, 2)->default(0);
            $table->decimal('used', 8, 2)->default(0);
            $table->decimal('balance', 8, 2)->default(0);
            $table->integer('year');
            $table->date('last_accrued_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
            $table->index(['user_id', 'leave_type_id', 'year']);
        });

        // Re-link leaves to new leave_types
        Schema::table('leaves', function (Blueprint $table) {
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('leaves', function (Blueprint $table) {
            try { $table->dropForeign(['leave_type_id']); } catch (\Throwable $e) {}
        });

        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');

        if (Schema::hasTable('leave_types_legacy')) {
            Schema::rename('leave_types_legacy', 'leave_types');
        }
        if (Schema::hasTable('leave_balances_legacy')) {
            Schema::rename('leave_balances_legacy', 'leave_balances');
        }

        Schema::enableForeignKeyConstraints();
    }
};
