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

    public function test_non_permanent_sales_payment_depends_on_achievement_on_the_20th()
    {
        $department = Department::create([
            'name' => 'Sales',
            'code' => 'SAL',
            'is_active' => true,
        ]);

        $designation = Designation::create([
            'name' => 'Sales Agent',
            'code' => 'SA-NP-1',
            'department_id' => $department->id,
            'basic_salary' => 80000,
            'travel_reimbursement' => 10000,
            'vehicle_rental' => 6000,
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => 'User NP 1',
            'username' => 'u_np1',
            'email' => 'u_np1@example.test',
            'password' => 'secret',
            'user_type' => 'user',
        ]);

        $employee = Employee::create([
            'full_name' => 'Non-perm Sales 1',
            'employee_code' => 'RT-NP-1',
            'id_number' => 'ID-NP-1',
            'date_of_birth' => '1995-05-05',
            'phone_primary' => '0770000099',
            'joined_at' => '2024-01-01',
            'department_id' => $department->id,
            'designation_id' => $designation->id,
            'employee_type' => 'non_permanent',
            'employment_status' => 'active',
        ]);
        $user->update(['employee_id' => $employee->id]);

        // Mock CDP service with 60% achievement (matrix yields 50% payout)
        $cdpMock = $this->createMock(\App\Services\CdpConnectService::class);
        $cdpMock->method('fetchEmployeeMetrics')->willReturn([
            'metrics' => [
                'achievement_percentage' => 60.0,
                'commission' => 0.0,
                'override_commission' => 0.0,
                'recover_amount' => 0.0,
            ],
        ]);
        $this->app->instance(\App\Services\CdpConnectService::class, $cdpMock);

        $records = (new PayrollCalculationService())->processEmployee('2026-08', $employee->fresh());

        // Non-permanent gets no 5th (basic) record
        $this->assertNull(collect($records)->firstWhere('pay_day', 'basic'));

        // 20th payment: all components must be scaled to 50%
        $allowance = collect($records)->firstWhere('pay_day', 'allowance');
        $this->assertNotNull($allowance, 'Expected a 20th allowance record');
        $this->assertEquals(40000.00, (float) $allowance->basic, 'Basic salary must be scaled to 50%');
        $this->assertEquals(5000.00, (float) $allowance->travel_reimbursement, 'Fuel must be scaled to 50%');
        $this->assertEquals(3000.00, (float) $allowance->vehicle_rental, 'Vehicle allowance must be scaled to 50%');
        $this->assertEquals(48000.00, (float) $allowance->how_much_paid, 'Gross paid must be 50% of (basic + fuel + vehicle)');
        $this->assertEquals(48000.00, (float) $allowance->net);
    }

    public function test_non_permanent_sales_payment_is_zero_when_achievement_below_fifty_percent()
    {
        $department = Department::create([
            'name' => 'Sales',
            'code' => 'SAL2',
            'is_active' => true,
        ]);

        $designation = Designation::create([
            'name' => 'Sales Agent 2',
            'code' => 'SA-NP-2',
            'department_id' => $department->id,
            'basic_salary' => 80000,
            'travel_reimbursement' => 10000,
            'vehicle_rental' => 6000,
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => 'User NP 2',
            'username' => 'u_np2',
            'email' => 'u_np2@example.test',
            'password' => 'secret',
            'user_type' => 'user',
        ]);

        $employee = Employee::create([
            'full_name' => 'Non-perm Sales 2',
            'employee_code' => 'RT-NP-2',
            'id_number' => 'ID-NP-2',
            'date_of_birth' => '1995-05-05',
            'phone_primary' => '0770000098',
            'joined_at' => '2024-01-01',
            'department_id' => $department->id,
            'designation_id' => $designation->id,
            'employee_type' => 'contract',
            'employment_status' => 'active',
        ]);
        $user->update(['employee_id' => $employee->id]);

        // Mock CDP service with 40% achievement (< 50% yields 0% payout)
        $cdpMock = $this->createMock(\App\Services\CdpConnectService::class);
        $cdpMock->method('fetchEmployeeMetrics')->willReturn([
            'metrics' => [
                'achievement_percentage' => 40.0,
                'commission' => 0.0,
                'override_commission' => 0.0,
                'recover_amount' => 0.0,
            ],
        ]);
        $this->app->instance(\App\Services\CdpConnectService::class, $cdpMock);

        $records = (new PayrollCalculationService())->processEmployee('2026-08', $employee->fresh());

        $allowance = collect($records)->firstWhere('pay_day', 'allowance');
        $this->assertNotNull($allowance, 'Expected a 20th allowance record with zero payout');
        $this->assertEquals(0.00, (float) $allowance->basic);
        $this->assertEquals(0.00, (float) $allowance->travel_reimbursement);
        $this->assertEquals(0.00, (float) $allowance->vehicle_rental);
        $this->assertEquals(0.00, (float) $allowance->how_much_paid);
        $this->assertEquals(0.00, (float) $allowance->net);
    }
}
