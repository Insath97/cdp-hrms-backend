<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->decimal('wht_tax', 12, 2)->default(0)->after('paye_tax');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropColumn('wht_tax');
        });
    }
};
