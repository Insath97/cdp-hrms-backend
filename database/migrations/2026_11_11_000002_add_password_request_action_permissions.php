<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Separate action permissions for password change requests, so a role can
     * be allowed to approve, reject, or merely see requests independently.
     *
     * @var array<int, string>
     */
    private array $permissions = [
        'Password Change Request Approve',
        'Password Change Request Reject',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'api'],
                ['group_name' => 'Password Change Request Permissions']
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Keep current behaviour: both roles that manage password requests can
        // still approve and reject. Adjust per role in Access Control > Roles.
        $this->grant('Super Admin', $this->permissions);
        $this->grant('System Admin', $this->permissions);
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
