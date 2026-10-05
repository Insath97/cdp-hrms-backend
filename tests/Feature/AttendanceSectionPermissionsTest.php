<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceSectionPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_attendance_section_permissions_for_super_admin()
    {
        $this->seed(\Database\Seeders\PermissionsSeeder::class);

        $expected = [
            'Attendance View Team',
            'Attendance View Company',
            'Attendance Correct',
            'Attendance Update Requests',
            'Attendance Settings',
        ];

        $role = Role::where('name', 'Super Admin')->firstOrFail();

        foreach ($expected as $name) {
            $permission = Permission::where('name', $name)->first();
            $this->assertNotNull($permission, "Permission '{$name}' was not seeded");
            $this->assertSame('api', $permission->guard_name);
            $this->assertSame('Attendance Management Permissions', $permission->group_name);
            $this->assertTrue(
                $role->hasPermissionTo($name),
                "Super Admin is missing '{$name}'"
            );
        }
    }
}
