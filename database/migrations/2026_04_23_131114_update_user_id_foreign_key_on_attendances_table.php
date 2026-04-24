<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // First ensure column exists (safety check)
            if (!Schema::hasColumn('attendances', 'user_id')) {
                $table->foreignId('user_id')->after('id');
            }

            // Drop existing constraint if any (important)
            $table->dropForeign(['user_id']);

            // Recreate correct foreign key
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
