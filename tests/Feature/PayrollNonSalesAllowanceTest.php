<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Services\PayrollCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollNonSalesAllowanceTest extends TestCase
{
    use RefreshDatabase;

    private function seedEmployee(string $departmentName, string $code): Employee
    {
        $department = Department::create([
            'name' => $departmentName,
            'code' => strtoupper(substr($departmentName, 0, 3)),
            'is_active' => true,
        ]);

        $designation = Designation::create([
            'name' => 'Tester',
            'code' => 'TST-' . $code,
            'department_id' => $department->id,
            'basic_salary' => 100000,
            'travel_reimbursement' => 10000,
            'vehicle_rental' => 5000,
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => "User {$code}",
            'username' => 'u_' . strtolower($code),
            'email' => 'u_' . strtolower($code) . '@example.test',
            'password' => 'secret',
            'user_type' => 'admin',
        ]);

        $employee = Employee::create([
            'full_name' => "Employee {$code}",
            'employee_code' => $code,
            'id_number' => 'ID-' . $code,
            'date_of_birth' => '1990-01-15',
            'phone_primary' => '0770000001',
            'joined_at' => '2024-01-01',
            'department_id' => $department->id,
            'designation_id' => $designation->id,
            'employee_type' => 'permanent',
            'employment_status' => 'active',
        ]);

        $user->update(['employee_id' => $employee->id]);

        return $employee->fresh();
    }

    public function test_non_sales_allowance_is_paid_at_exact_package_amounts()
    {
        $employee = $this->seedEmployee('Engineering', 'E-NS-1');

        $records = (new PayrollCalculationService())->processEmployee('2026-08', $employee);

        $allowance = collect($records)->firstWhere('pay_day', 'allowance');
        $this->assertNotNull($allowance, 'Expected a 20th allowance record');

        // Travel and vehicle must NOT be scaled down by (absent) metrics.
        $this->assertEquals(10000.00, (float) $allowance->travel_reimbursement);
        $this->assertEquals(5000.00, (float) $allowance->vehicle_rental);
        $this->assertEquals(15000.00, (float) $allowance->how_much_paid);
    }

    public function test_non_sales_basic_is_paid_in_full_on_the_5th()
    {
        $employee = $this->seedEmployee('Engineering', 'E-NS-2');

        $records = (new PayrollCalculationService())->processEmployee('2026-08', $employee);

        $basic = collect($records)->firstWhere('pay_day', 'basic');
        $this->assertNotNull($basic, 'Expected a 5th basic record');
        $this->assertEquals(100000.00, (float) $basic->basic);

        // No commission record for non-sales staff.
        $this->assertNull(collect($records)->firstWhere('pay_day', 'commission'));
    }
}
