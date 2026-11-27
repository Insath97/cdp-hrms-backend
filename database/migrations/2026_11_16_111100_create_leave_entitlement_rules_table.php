<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_entitlement_rules', function (Blueprint $table) {
            $table->id();
            $table->string('category'); // annual, casual
            $table->unsignedTinyInteger('service_year_from');
            $table->unsignedTinyInteger('service_year_to')->nullable(); // null = open-ended (e.g. 3+)
            $table->unsignedTinyInteger('join_month_from')->nullable();
            $table->unsignedTinyInteger('join_month_to')->nullable();
            $table->decimal('entitlement_days', 8, 2)->default(0);
            $table->string('accrual_type')->default('grant'); // grant, monthly_on_completion
            $table->decimal('max_days', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_entitlement_rules');
    }
};
