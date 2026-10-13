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
        Schema::table('attendance_update_requests', function (Blueprint $table) {
            $table->dateTime('previous_clock_in')->nullable()->after('requested_clock_out');
            $table->dateTime('previous_clock_out')->nullable()->after('previous_clock_in');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_update_requests', function (Blueprint $table) {
            $table->dropColumn(['previous_clock_in', 'previous_clock_out']);
        });
    }
};
