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
            $table->integer('late_minutes')->default(0)->after('working_hours');
            $table->boolean('exceeds_grace_period')->default(false)->after('late_minutes');
            $table->integer('grace_period_applied')->default(5)->after('exceeds_grace_period');
            $table->timestamp('converted_at')->nullable()->after('exceeds_grace_period');
            $table->string('converted_leave_type')->nullable()->after('converted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
             $table->dropColumn([
                'late_minutes',
                'exceeds_grace_period',
                'grace_period_applied',
                'converted_at',
                'converted_leave_type'
            ]);
        });
    }
};
