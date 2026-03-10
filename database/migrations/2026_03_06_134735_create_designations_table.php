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
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Software Engineer, HR Manager
            $table->string('code')->unique(); // SE, HRM
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->enum('level', ['entry', 'mid', 'senior', 'lead','executive','Manager','Director'])->default('entry');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('designations');
    }
};
