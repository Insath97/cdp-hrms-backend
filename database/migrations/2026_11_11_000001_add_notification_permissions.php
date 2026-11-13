<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Notification permissions let each role opt in to a kind of group
     * notification. Personal notifications (e.g. "your request was approved")
     * are not controlled by these permissions.
     *
     * @var array<int, string>
     */
    private array $permissions = [
        'Notification Password Change Request',
        'Notification Leave Request',
        'Notification Attendance Correction',
        'Notification Payroll Request',
        'Notification Open Location Request',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'api'],
                ['group_name' => 'Notification Permissions']
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Keep the current behaviour: both admin roles receive password
        // request notifications. Custom roles can be granted the permission
        // later from Access Control > Roles.
        $this->grant('Super Admin', $this->permissions);
        $this->grant('System Admin', $this->permissions);
        $this->grant('HR Admin', [
            'Notification Leave Request',
            'Notification Attendance Correction',
        ]);
    }

    public function down(): void
    {
        Permission::whereIn('name', $this->permissions)
            ->where('guard_name', 'api')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function grant(string $roleName, array $permissions): void
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();

        if (! $role) {
            return;
        }

        foreach ($permissions as $permissionName) {
            $permission = Permission::where('name', $permissionName)
                ->where('guard_name', 'api')
                ->first();

            if ($permission && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
    }
};
