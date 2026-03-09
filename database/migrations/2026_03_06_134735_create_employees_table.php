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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('f_name');
            $table->string('l_name');
            $table->string('full_name');
            $table->string('name_with_initials');
            $table->string('profile_image')->nullable();
            $table->string('employee_code')->unique();
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');

            $table->enum('id_type', ['nic', 'passport', 'driving_license', 'other'])->default('nic');
            $table->string('id_number')->unique();

            $table->date('date_of_birth');

            $table->string('email')->unique();
            $table->string('phone')->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->default('Sri Lanka');
            $table->string('postal_code')->nullable();
            $table->string('phone_primary');
            $table->string('phone_secondary')->nullable();
            $table->boolean('have_whatsapp')->default(false);
            $table->string('whatsapp_number')->nullable();

            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->enum('employment_status', ['active', 'inactive', 'terminated'])->default('active');
            $table->string('designation');
            $table->decimal('basic_salary', 10, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
