<?php
// database/migrations/2024_05_07_000002_update_leave_balances_add_user_id.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();      // Added user_id
            $table->unsignedBigInteger('employee_id')->nullable();  // Keep employee_id
            $table->unsignedBigInteger('leave_type_id');
            $table->integer('year');
            $table->decimal('allocated', 8, 2)->default(0);
            $table->decimal('used', 8, 2)->default(0);
            $table->decimal('remaining', 8, 2)->default(0);
            $table->decimal('pending', 8, 2)->default(0);
            $table->timestamps();

            // Foreign keys - make them nullable and use SET NULL on delete
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('set null');
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->onDelete('cascade');

            // Unique constraint to prevent duplicates
            $table->unique(['user_id', 'employee_id', 'leave_type_id', 'year'], 'leave_balances_unique');

            // Indexes for faster queries
            $table->index(['user_id', 'year']);
            $table->index(['employee_id', 'year']);
            $table->index('leave_type_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('leave_balances');
    }
};
