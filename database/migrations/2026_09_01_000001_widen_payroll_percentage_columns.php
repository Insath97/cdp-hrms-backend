<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->decimal('achievement_percentage', 12, 2)->change();
            $table->decimal('payment_percentage', 12, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->decimal('achievement_percentage', 5, 2)->change();
            $table->decimal('payment_percentage', 5, 2)->change();
        });
    }
};
