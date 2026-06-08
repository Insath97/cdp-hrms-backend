<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Designation;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DesignationTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_package_is_calculated_automatically_on_creation()
    {
        $department = Department::create([
            'name' => 'Engineering',
            'code' => 'ENG',
            'is_active' => true
        ]);

        $designation = Designation::create([
            'name' => 'Senior Developer',
            'code' => 'SDEV',
            'department_id' => $department->id,
            'basic_salary' => 80000,
            'travel_reimbursement' => 5000,
            'vehicle_rental' => 10000,
            'performance_allowance' => 15000,
            'incentive' => 8000,
            'position_allowance' => 12000,
            'level' => 'senior',
            'is_active' => true
        ]);

        $this->assertEquals(130000, $designation->total_package);
    }

    public function test_total_package_is_recalculated_on_update()
    {
        $department = Department::create([
            'name' => 'Engineering',
            'code' => 'ENG',
            'is_active' => true
        ]);

        $designation = Designation::create([
            'name' => 'Senior Developer',
            'code' => 'SDEV',
            'department_id' => $department->id,
            'basic_salary' => 80000,
            'travel_reimbursement' => 5000,
            'vehicle_rental' => 10000,
            'performance_allowance' => 15000,
            'incentive' => 8000,
            'position_allowance' => 12000,
            'level' => 'senior',
            'is_active' => true
        ]);

        $this->assertEquals(130000, $designation->total_package);

        $designation->update([
            'basic_salary' => 90000,
            'incentive' => 0
        ]);

        $this->assertEquals(132000, $designation->total_package);
    }
}
