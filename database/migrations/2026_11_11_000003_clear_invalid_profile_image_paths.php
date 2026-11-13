<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Older rows stored the literal placeholder "/image" (or "image") in the
     * profile_image column. It is not a real upload path, so the frontend
     * rendered a broken image instead of falling back to the user's initials.
     */
    public function up(): void
    {
        $invalidPaths = ['/image', 'image'];

        DB::table('employees')
            ->whereIn('profile_image', $invalidPaths)
            ->update(['profile_image' => null]);

        DB::table('users')
            ->whereIn('profile_image', $invalidPaths)
            ->update(['profile_image' => null]);
    }

    public function down(): void
    {
        // The placeholder carried no information, so there is nothing sensible
        // to restore. Leaving the values null is intentional.
    }
};
