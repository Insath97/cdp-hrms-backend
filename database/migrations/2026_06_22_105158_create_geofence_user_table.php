<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofence_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('geofence_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            $table->unique(['user_id', 'employee_id','geofence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofence_user');
    }
};
