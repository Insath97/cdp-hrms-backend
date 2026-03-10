<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            /* Access Management */
            ['name' => 'Permission Index', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Permission Create', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Permission Update', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Permission Delete', 'group_name' => 'Access Management Permissions'],

            ['name' => 'Role Index', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Role Create', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Role Update', 'group_name' => 'Access Management Permissions'],
            ['name' => 'Role Delete', 'group_name' => 'Access Management Permissions'],

            /* User Management */
            ['name' => 'User Index', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Create', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Update', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Delete', 'group_name' => 'User Management Permissions'],
            ['name' => 'User Toggle Status', 'group_name' => 'User Management Permissions'],

            ['name' => 'Employee Index', 'group_name' => 'Employee Management Permissions'],
            ['name' => 'Employee Create', 'group_name' => 'Employee Management Permissions'],
            ['name' => 'Employee Update', 'group_name' => 'Employee Management Permissions'],
            ['name' => 'Employee Delete', 'group_name' => 'Employee Management Permissions'],
            ['name' => 'Employee Restore', 'group_name' => 'Employee Management Permissions'],
            ['name' => 'Employee Toggle Status', 'group_name' => 'Employee Management Permissions'],

            ['name' => 'Province Index', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Province Create', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Province Update', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Province Delete', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Province Toggle Status', 'group_name' => 'Region Management Permissions'],

            ['name' => 'Region Index', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Create', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Update', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Delete', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Region Toggle Status', 'group_name' => 'Region Management Permissions'],

            ['name' => 'Zonal Index', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Zonal Create', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Zonal Update', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Zonal Delete', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Zonal Toggle Status', 'group_name' => 'Region Management Permissions'],

            ['name' => 'Branch Index', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Branch Create', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Branch Update', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Branch Delete', 'group_name' => 'Region Management Permissions'],
            ['name' => 'Branch Toggle Status', 'group_name' => 'Region Management Permissions'],

            ['name' => 'Designation Index', 'group_name' => 'Employee Management Permissions'],
            ['name' => 'Designation Create', 'group_name' => 'Employee Management Permissions'],
            ['name' => 'Designation Update', 'group_name' => 'Employee Management Permissions'],
            ['name' => 'Designation Delete', 'group_name' => 'Employee Management Permissions'],
            ['name' => 'Designation Toggle Status', 'group_name' => 'Employee Management Permissions'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission['name'],
                'group_name' => $permission['group_name'],
                'guard_name' => 'api',
            ]);
        }

        $role = Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super Admin']);

        $allPermissions = Permission::all();
        $role->syncPermissions($allPermissions);
    }
}
