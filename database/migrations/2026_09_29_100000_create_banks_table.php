<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('swift_code', 11)->nullable();
            $table->unsignedSmallInteger('account_number_length')->nullable();
            $table->string('account_number_format')->nullable();
            $table->string('account_number_example')->nullable();
            $table->boolean('is_commercial')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
