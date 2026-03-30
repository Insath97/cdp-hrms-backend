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
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('calculation_unit', ['days', 'hours'])->default('days');
            $table->decimal('default_allocation', 10, 2);
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
