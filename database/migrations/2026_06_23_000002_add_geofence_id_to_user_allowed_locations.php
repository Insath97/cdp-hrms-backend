<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_allowed_locations', function (Blueprint $table) {
            $table->foreignId('geofence_id')->nullable()->constrained('geofences')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_allowed_locations', function (Blueprint $table) {
            $table->dropForeign(['geofence_id']);
            $table->dropColumn('geofence_id');
        });
    }
};
