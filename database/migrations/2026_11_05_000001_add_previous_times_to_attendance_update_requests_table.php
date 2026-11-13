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
        // Some environments already added these columns through an older
        // migration (e.g. 2026_10_12_100000). Only add the missing ones so
        // this migration stays safe on databases that predate it.
        $hasPreviousClockIn = Schema::hasColumn('attendance_update_requests', 'previous_clock_in');
        $hasPreviousClockOut = Schema::hasColumn('attendance_update_requests', 'previous_clock_out');

        if ($hasPreviousClockIn && $hasPreviousClockOut) {
            return;
        }

        Schema::table('attendance_update_requests', function (Blueprint $table) use ($hasPreviousClockIn, $hasPreviousClockOut) {
            if (! $hasPreviousClockIn) {
                $table->dateTime('previous_clock_in')->nullable()->after('requested_clock_in');
            }

            if (! $hasPreviousClockOut) {
                $table->dateTime('previous_clock_out')->nullable()->after('requested_clock_out');
            }
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
