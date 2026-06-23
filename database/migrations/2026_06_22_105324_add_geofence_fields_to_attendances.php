<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('in_geofence_name')->nullable()->after('in_longitude');
            $table->string('out_geofence_name')->nullable()->after('out_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['in_geofence_name', 'out_geofence_name']);
        });
    }
};
