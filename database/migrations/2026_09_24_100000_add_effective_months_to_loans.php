<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('effective_from', 7)->nullable()->after('start_date'); // YYYY-MM
            $table->string('effective_to', 7)->nullable()->after('effective_from'); // YYYY-MM, null = ongoing
        });

        // Backfill from legacy start_date / end_date columns
        $rows = DB::table('loans')->select('id', 'start_date', 'end_date', 'effective_from', 'effective_to')->get();
        foreach ($rows as $row) {
            $updates = [];
            if ($row->start_date && ! $row->effective_from) {
                $updates['effective_from'] = Carbon::parse($row->start_date)->format('Y-m');
            }
            if ($row->end_date && ! $row->effective_to) {
                $updates['effective_to'] = Carbon::parse($row->end_date)->format('Y-m');
            }
            if ($updates) {
                DB::table('loans')->where('id', $row->id)->update($updates);
            }
        }

        // Any rows still missing a start month fall back to the current month
        DB::table('loans')->whereNull('effective_from')->update(['effective_from' => now()->format('Y-m')]);

        Schema::table('loans', function (Blueprint $table) {
            $table->string('effective_from', 7)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['effective_from', 'effective_to']);
        });
    }
};