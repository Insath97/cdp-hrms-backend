<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('label', 191);
            $table->string('value', 255)->default('');
            $table->string('type', 20)->default('number'); // number | boolean | text
            $table->timestamps();
        });

        DB::table('payroll_settings')->insert([
            ['key' => 'stamp_fee_amount',   'label' => 'Stamp Fee Amount (Rs)',   'value' => '25',  'type' => 'number'],
            ['key' => 'stamp_fee_threshold','label' => 'Stamp Fee Threshold (Rs)', 'value' => '25000','type' => 'number'],
            ['key' => 'wht_rate',           'label' => 'WHT Rate %',               'value' => '5',   'type' => 'number'],
            ['key' => 'wht_threshold',      'label' => 'WHT Threshold (Rs)',       'value' => '100000','type' => 'number'],
            ['key' => 'mobile_payment_applicable', 'label' => 'Mobile Payment Applicable', 'value' => '0', 'type' => 'boolean'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settings');
    }
};