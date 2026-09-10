<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_salary_details', function (Blueprint $table) {
            $table->foreignId('designation_id')->nullable()->after('employee_id');
            $table->string('designation_name')->nullable()->after('designation_id');
        });
    }

    public function down(): void
    {
        Schema::table('employee_salary_details', function (Blueprint $table) {
            $table->dropForeign(['designation_id']);
            $table->dropColumn(['designation_id', 'designation_name']);
        });
    }
};
