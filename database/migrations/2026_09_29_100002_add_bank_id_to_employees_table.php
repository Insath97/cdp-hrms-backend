<?php

use App\Models\Bank;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('bank_id')
                ->nullable()
                ->after('bank_name')
                ->constrained('banks')
                ->nullOnDelete();
        });

        DB::table('banks')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function ($bank) {
                DB::table('employees')
                    ->whereNull('bank_id')
                    ->where('bank_name', $bank->name)
                    ->update(['bank_id' => $bank->id]);
            });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign('employees_bank_id_foreign');
            $table->dropColumn('bank_id');
        });
    }
};
