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
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->string('type')->nullable(); // 'public', 'poya', etc.
            $table->boolean('is_company_holiday')->default(true);
            $table->integer('year')->index();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['date', 'is_company_holiday']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
