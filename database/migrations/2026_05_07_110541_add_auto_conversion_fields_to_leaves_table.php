<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('leaves', function (Blueprint $table) {
            if (!Schema::hasColumn('leaves', 'is_auto_converted')) {
                $table->boolean('is_auto_converted')->default(false)->after('reject_reason');
            }
            if (!Schema::hasColumn('leaves', 'consecutive_late_days')) {
                $table->integer('consecutive_late_days')->nullable()->after('is_auto_converted');
            }
            if (!Schema::hasColumn('leaves', 'grace_period_at_conversion')) {
                $table->integer('grace_period_at_conversion')->nullable()->after('consecutive_late_days');
            }
        });
    }

    public function down()
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropColumn(['is_auto_converted', 'consecutive_late_days', 'grace_period_at_conversion']);
        });
    }
};
