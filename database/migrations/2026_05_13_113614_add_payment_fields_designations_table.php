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
        Schema::table('designations', function (Blueprint $table) {
            $table->integer('monthly_target')->default(0)->after('department_id');
            $table->integer('basic_salary')->default(0)->after('monthly_target');
            $table->integer('travel_reimbursement')->default(0)->after('basic_salary');
            $table->integer('vehicle_rental')->default(0)->after('travel_reimbursement');
            $table->integer('performance_allowance')->default(0)->after('vehicle_rental');
            $table->integer('incentive')->default(0)->after('performance_allowance');
            $table->integer('position_allowance')->default(0)->after('incentive');
            $table->integer('total_package')->default(0)->after('position_allowance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('designations', function (Blueprint $table) {
            $table->dropColumn([
                'monthly_target',
                'basic_salary', 
                'travel_reimbursement', 
                'vehicle_rental', 
                'performance_allowance', 
                'incentive',
                'position_allowance',
                'total_package'
            ]);
        });
    }
};
