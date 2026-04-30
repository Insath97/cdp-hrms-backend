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
        Schema::table('employees', function (Blueprint $table) {
            $table->string('profile_image')->nullable()->after('employee_code');
            $table->string('bank_name')->nullable()->after('basic_salary');
            $table->string('bank_branch')->nullable()->after('bank_name');
            $table->string('account_number')->nullable()->after('bank_branch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['profile_image', 'bank_name', 'bank_branch', 'account_number']);
        });
    }
};
