<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_allowed_locations', function (Blueprint $table) {
            $table->dropColumn(['name', 'latitude', 'longitude', 'radius_meters']);
        });
    }

    public function down(): void
    {
        Schema::table('user_allowed_locations', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('radius_meters', 10, 2)->default(50)->nullable();
        });
    }
};
