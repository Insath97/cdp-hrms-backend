<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store approvers as users so an admin without an employee record can
     * still be recorded on an approved/rejected attendance update request.
     */
    public function up(): void
    {
        Schema::table('attendance_update_requests', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
        });

        // Existing values are employee IDs — map them to the linked user.
        DB::statement('
            UPDATE attendance_update_requests ar
            JOIN employees e ON e.id = ar.approved_by
            JOIN users u ON u.employee_id = e.id
            SET ar.approved_by = u.id
            WHERE ar.approved_by IS NOT NULL
        ');
        DB::statement('
            UPDATE attendance_update_requests ar
            JOIN employees e ON e.id = ar.rejected_by
            JOIN users u ON u.employee_id = e.id
            SET ar.rejected_by = u.id
            WHERE ar.rejected_by IS NOT NULL
        ');

        // Drop any value that cannot be resolved to a user before restoring
        // the foreign keys, otherwise the migration would fail.
        DB::statement('UPDATE attendance_update_requests SET approved_by = NULL WHERE approved_by IS NOT NULL AND approved_by NOT IN (SELECT id FROM users)');
        DB::statement('UPDATE attendance_update_requests SET rejected_by = NULL WHERE rejected_by IS NOT NULL AND rejected_by NOT IN (SELECT id FROM users)');

        // Legacy requests with no manager could only have been actioned by a
        // Super Admin, who often has no employee record to map from.
        $superAdminId = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'Super Admin')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->orderBy('model_has_roles.model_id')
            ->value('model_has_roles.model_id');

        if ($superAdminId) {
            DB::table('attendance_update_requests')
                ->where('status', 'approved')
                ->whereNull('manager_id')
                ->whereNull('approved_by')
                ->update(['approved_by' => $superAdminId]);

            DB::table('attendance_update_requests')
                ->where('status', 'rejected')
                ->whereNull('manager_id')
                ->whereNull('rejected_by')
                ->update(['rejected_by' => $superAdminId]);
        }

        Schema::table('attendance_update_requests', function (Blueprint $table) {
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_update_requests', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
        });

        DB::statement('
            UPDATE attendance_update_requests ar
            JOIN users u ON u.id = ar.approved_by
            JOIN employees e ON e.id = u.employee_id
            SET ar.approved_by = e.id
            WHERE ar.approved_by IS NOT NULL
        ');
        DB::statement('
            UPDATE attendance_update_requests ar
            JOIN users u ON u.id = ar.rejected_by
            JOIN employees e ON e.id = u.employee_id
            SET ar.rejected_by = e.id
            WHERE ar.rejected_by IS NOT NULL
        ');
        DB::statement('UPDATE attendance_update_requests SET approved_by = NULL WHERE approved_by IS NOT NULL AND approved_by NOT IN (SELECT id FROM employees)');
        DB::statement('UPDATE attendance_update_requests SET rejected_by = NULL WHERE rejected_by IS NOT NULL AND rejected_by NOT IN (SELECT id FROM employees)');

        Schema::table('attendance_update_requests', function (Blueprint $table) {
            $table->foreign('approved_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('employees')->nullOnDelete();
        });
    }
};
