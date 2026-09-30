<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollMonth;
use App\Models\PayrollRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollBatchStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $name, string $username): User
    {
        return User::create([
            'name' => $name,
            'username' => $username,
            'email' => $username . '@example.test',
            'password' => 'secret',
            'user_type' => 'admin',
        ]);
    }

    private function userWith(string $permissionName): User
    {
        $user = $this->makeUser('Perm ' . $permissionName, 'u' . substr(md5($permissionName . random_int(1, 999999)), 0, 10));
        $user->assignRole(Role::findOrCreate('tester', 'api'));
        $user->givePermissionTo(
            Permission::firstOrCreate([
                'name' => $permissionName,
                'group_name' => 'Payroll Management Permissions',
                'guard_name' => 'api',
            ])
        );

        return $user;
    }

    /**
     * Mirrors PayrollDeductionApprovalTest: a real JWT token for the outer
     * guard plus an authenticated "web" guard user for sanctum.
     */
    private function actingAsUser(User $user): self
    {
        Auth::guard('web')->setUser($user);
        $token = auth('api')->login($user);

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function seedEmployee(string $code = 'E-0001'): Employee
    {
        $department = Department::firstOrCreate(
            ['name' => 'Engineering'],
            ['code' => 'ENG', 'is_active' => true]
        );

        $user = $this->makeUser("User {$code}", strtolower(str_replace(['-', ' '], '_', $code)));

        $employee = Employee::create([
            'full_name' => "Employee {$code}",
            'employee_code' => $code,
            'id_number' => 'ID-' . $code,
            'date_of_birth' => '1990-01-15',
            'phone_primary' => '0770000001',
            'joined_at' => '2024-01-01',
            'department_id' => $department->id,
        ]);

        // Link the user to the employee the same way the app does.
        $user->update(['employee_id' => $employee->id]);

        return $employee;
    }

    private function seedRecord(?Employee $employee, string $month, string $payDay, string $status = 'draft'): PayrollRecord
    {
        return PayrollRecord::create([
            'user_id' => $employee?->user?->id,
            'employee_id' => $employee?->id,
            'month' => $month,
            'pay_day' => $payDay,
            'basic' => 100000,
            'net' => 95000,
            'achievement_percentage' => 0,
            'payment_percentage' => 100,
            'status' => $status,
        ]);
    }

    public function test_it_requires_the_payroll_process_permission()
    {
        $employee = $this->seedEmployee();
        $this->seedRecord($employee, '2026-08', 'basic');

        $noPerm = $this->userWith('Payroll View');
        $this->actingAsUser($noPerm)
            ->postJson('/api/v1/admin/payroll/process-batch-status', ['month' => '2026-08'])
            ->assertForbidden();
    }

    public function test_it_refuses_to_process_a_locked_month()
    {
        $employee = $this->seedEmployee();
        $record = $this->seedRecord($employee, '2026-08', 'basic');

        PayrollMonth::create(['month' => '2026-08', 'status' => 'locked']);

        $processor = $this->userWith('Payroll Process');
        $this->actingAsUser($processor)
            ->postJson('/api/v1/admin/payroll/process-batch-status', ['month' => '2026-08'])
            ->assertStatus(403);

        $this->assertSame('draft', $record->fresh()->status);
    }

    public function test_it_flips_all_draft_records_to_processed_with_a_timestamp()
    {
        $employeeA = $this->seedEmployee('E-0001');
        $employeeB = $this->seedEmployee('E-0002');

        // 3 records for A, 1 for B = 4 records across 2 employees.
        $this->seedRecord($employeeA, '2026-08', 'basic');
        $this->seedRecord($employeeA, '2026-08', 'commission');
        $this->seedRecord($employeeA, '2026-08', 'allowance');
        $this->seedRecord($employeeB, '2026-08', 'basic');

        // An already-processed record must be left alone.
        $already = $this->seedRecord($employeeB, '2026-08', 'allowance', 'processed');

        $processor = $this->userWith('Payroll Process');
        $this->actingAsUser($processor)
            ->postJson('/api/v1/admin/payroll/process-batch-status', ['month' => '2026-08'])
            ->assertOk()
            ->assertJsonPath('data.processed', 4)
            ->assertJsonPath('data.processed_employees', 2);

        $this->assertSame(0, PayrollRecord::where('month', '2026-08')->where('status', 'draft')->count());

        // The already-processed record must be left completely untouched.
        $this->assertSame('processed', $already->fresh()->status);
        $this->assertNull($already->fresh()->processed_at);
    }

    public function test_it_can_limit_processing_to_specific_pay_days()
    {
        $employee = $this->seedEmployee();
        $basic = $this->seedRecord($employee, '2026-08', 'basic');
        $commission = $this->seedRecord($employee, '2026-08', 'commission');

        $processor = $this->userWith('Payroll Process');
        $this->actingAsUser($processor)
            ->postJson('/api/v1/admin/payroll/process-batch-status', [
                'month' => '2026-08',
                'pay_days' => ['basic'],
            ])
            ->assertOk()
            ->assertJsonPath('data.processed', 1);

        $this->assertSame('processed', $basic->fresh()->status);
        $this->assertSame('draft', $commission->fresh()->status);
    }

    public function test_it_returns_422_when_there_is_nothing_to_process()
    {
        $processor = $this->userWith('Payroll Process');
        $this->actingAsUser($processor)
            ->postJson('/api/v1/admin/payroll/process-batch-status', ['month' => '2026-08'])
            ->assertStatus(422);
    }

    public function test_it_validates_pay_day_values()
    {
        $processor = $this->userWith('Payroll Process');
        $this->actingAsUser($processor)
            ->postJson('/api/v1/admin/payroll/process-batch-status', [
                'month' => '2026-08',
                'pay_days' => ['not-a-pay-day'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pay_days.0');
    }

    public function test_generation_reports_the_employee_id_for_a_missing_employee()
    {
        $processor = $this->userWith('Payroll Generate');

        $response = $this->actingAsUser($processor)
            ->postJson('/api/v1/admin/payroll/generate-batch', [
                'month' => '2026-08',
                'user_ids' => [999999],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.generated', 0)
            ->assertJsonPath('data.generated_employees', 0)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.errors.0.user_id', 999999)
            ->assertJsonPath('data.errors.0.error', 'Employee not found');
    }

    public function test_generation_identifies_failed_employees_by_code_and_name()
    {
        $employee = $this->seedEmployee('E-0007');

        $processor = $this->userWith('Payroll Generate');
        $response = $this->actingAsUser($processor)
            ->postJson('/api/v1/admin/payroll/generate-batch', [
                'month' => '2026-08',
                'user_ids' => [$employee->id],
            ]);

        // No salary data is seeded, so generation must fail for this employee.
        $response->assertOk()
            ->assertJsonPath('data.generated', 0)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.errors.0.employee_id', $employee->id)
            ->assertJsonPath('data.errors.0.employee_code', 'E-0007')
            ->assertJsonPath('data.errors.0.employee_name', 'Employee E-0007');
    }
}
