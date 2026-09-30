<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Payroll deduction permissions used by the approval/report endpoints.
     */
    private const PERMISSIONS = [
        'Payroll Deduction Index',
        'Payroll Deduction Create',
        'Payroll Deduction Delete',
        'Payroll Deduction Approve',
        'Payroll Deduction Reject',
        'Payroll Deduction Report',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'group_name' => 'Payroll Management Permissions',
                'guard_name' => 'api',
            ]);
        }

        $role = Role::firstOrCreate([
            'name' => 'Super Admin',
            'guard_name' => 'api',
        ]);

        // Use givePermissionTo (additive) instead of syncPermissions (replaces all)
        // to avoid stripping existing permissions from the Super Admin role.
        $newPermissions = Permission::where('name', 'LIKE', 'Payroll Deduction%')->get();
        $role->givePermissionTo($newPermissions);
    }

    public function down(): void
    {
        $permissions = Permission::where('name', 'LIKE', 'Payroll Deduction%')->get();

        DB::table('role_has_permissions')
            ->whereIn('permission_id', $permissions->pluck('id'))
            ->delete();

        DB::table('permissions')
            ->whereIn('id', $permissions->pluck('id'))
            ->delete();
    }
};
