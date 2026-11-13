<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Corrections for days with no attendance record have no attendance id
        // yet, and users such as the seeded dev admin have no employee record.
        // Store the target date on the request and allow both columns to be
        // null until a Super Admin/manager approves the correction.
        Schema::table('attendance_update_requests', function (Blueprint $table) {
            $table->date('date')->nullable()->after('attendance_id');
            $table->foreignId('attendance_id')->nullable()->change();
            $table->foreignId('employee_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Requests that were never linked to an attendance record cannot be
        // represented once attendance_id is required again.
        DB::table('attendance_update_requests')->whereNull('attendance_id')->delete();
        DB::table('attendance_update_requests')->whereNull('employee_id')->delete();

        Schema::table('attendance_update_requests', function (Blueprint $table) {
            $table->dropColumn('date');
        });

        Schema::table('attendance_update_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('attendance_id')->nullable(false)->change();
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
        });
    }
};
