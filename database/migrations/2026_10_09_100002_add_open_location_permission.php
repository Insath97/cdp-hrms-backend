<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Permission required by HR to approve/reject open-location requests.
     */
    private const PERMISSION = 'Open Location Manage';

    public function up(): void
    {
        Permission::firstOrCreate([
            'name' => self::PERMISSION,
            'group_name' => 'Geofence Management Permissions',
            'guard_name' => 'api',
        ]);

        $role = Role::firstOrCreate([
            'name' => 'Super Admin',
            'guard_name' => 'api',
        ]);

        // Additive: avoid stripping existing permissions from the role.
        $role->givePermissionTo(self::PERMISSION);
    }

    public function down(): void
    {
        $permission = Permission::where('name', self::PERMISSION)->first();

        if ($permission) {
            DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
            DB::table('permissions')->where('id', $permission->id)->delete();
        }
    }
};
