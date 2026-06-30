<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE designations MODIFY COLUMN level ENUM('entry','mid','senior','lead','executive','Manager','Director') DEFAULT 'entry'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE designations MODIFY COLUMN level VARCHAR(255) DEFAULT NULL");
    }
};