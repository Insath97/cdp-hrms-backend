<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group')->index(); // attendance, leave, general, etc.
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, integer, boolean, json, decimal
            $table->text('description')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('system_settings');
    }
};
