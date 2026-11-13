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
        // Employees without a reporting manager still raise correction
        // requests; those are approved/rejected by a Super Admin.
        Schema::table('attendance_update_requests', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Make sure the column can be made non-nullable again by pointing any
        // manager-less requests at the employee they belong to.
        DB::table('attendance_update_requests')
            ->whereNull('manager_id')
            ->update(['manager_id' => DB::raw('employee_id')]);

        Schema::table('attendance_update_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('manager_id')->nullable(false)->change();
        });
    }
};
