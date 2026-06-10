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
        Schema::table('attendances', function (Blueprint $table) {
            $table->decimal('leave_taken', 3, 2)->default(0.00)->after('converted_leave_type');
            $table->boolean('is_no_pay')->default(false)->after('leave_taken');
            $table->text('remarks')->nullable()->after('is_no_pay');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['leave_taken', 'is_no_pay', 'remarks']);
        });
    }
};
