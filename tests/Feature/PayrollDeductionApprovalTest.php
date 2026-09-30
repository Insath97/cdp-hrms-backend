<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollDeduction;
use App\Models\PayrollRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollDeductionApprovalTest extends TestCase
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

    private function seedPayrollRecord(): PayrollRecord
    {
        $department = Department::create([
            'name' => 'Engineering',
            'code' => 'ENG',
            'is_active' => true,
        ]);

        $user = $this->makeUser('Jane Tester', 'jane.tester');

        $employee = Employee::create([
            'user_id' => $user->id,
            'full_name' => 'Jane Tester',
            'employee_code' => 'E-0001',
            'id_number' => 'ID-0001',
            'date_of_birth' => '1990-01-15',
            'phone_primary' => '0770000001',
            'joined_at' => '2024-01-01',
            'department_id' => $department->id,
        ]);

        $record = PayrollRecord::create([
            'user_id' => $user->id,
            'employee_id' => $employee->id,
            'month' => '2026-09',
            'basic' => 100000,
            'allowances' => 20000,
            'epf_employee' => 6000,
            'achievement_percentage' => 0,
            'payment_percentage' => 100,
            'status' => 'draft',
        ]);

        $record->setRelation('employee', $employee);

        return $record->fresh();
    }

    /**
     * The v1 group applies auth:api (JWT) and the admin group applies auth:sanctum.
     *
     * We need a real JWT bearer token for the outer guard, and an authenticated
     * "web" guard user for sanctum. We deliberately do NOT use actingAs($user, 'web')
     * because that calls Auth::shouldUse('web'), which changes Laravel's default guard
     * and makes Spatie look up permissions for the "web" guard instead of "api".
     */
    private function actingAsUser(User $user): self
    {
        Auth::guard('web')->setUser($user);

        $token = auth('api')->login($user);

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function userWith(string $permissionName): User
    {
        $user = $this->makeUser('Perm ' . $permissionName, 'u' . substr(md5($permissionName . random_int(1, 999999)), 0, 10));
        $user->assignRole(Role::findOrCreate('tester', 'api'));
        // permissions.group_name is NOT NULL, so it must be supplied on create.
        $user->givePermissionTo(
            Permission::firstOrCreate([
                'name' => $permissionName,
                'group_name' => 'Payroll Management Permissions',
                'guard_name' => 'api',
            ])
        );

        return $user;
    }

    public function test_report_requires_report_permission()
    {
        $this->seedPayrollRecord();

        $user = $this->userWith('Payroll Deduction Index');
        $this->actingAsUser($user)
            ->getJson('/api/v1/admin/payroll/deductions/report')
            ->assertForbidden();

        $reporter = $this->userWith('Payroll Deduction Report');
        $reporter->givePermissionTo('Payroll Deduction Index');
        $this->actingAsUser($reporter)
            ->getJson('/api/v1/admin/payroll/deductions/report')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data' => ['data', 'current_page', 'last_page', 'total'],
                'summary' => ['by_status', 'by_type', 'total_count', 'total_amount'],
            ]);
    }

    public function test_approve_marks_deduction_approved_and_recalculates_payroll()
    {
        $record = $this->seedPayrollRecord();

        $deduction = PayrollDeduction::create([
            'payroll_record_id' => $record->id,
            'type' => 'other',
            'label' => 'Pending fine',
            'amount' => 2500,
            'approval_status' => 'pending',
            'is_auto' => false,
        ]);

        $approver = $this->userWith('Payroll Deduction Approve');
        $this->actingAsUser($approver)
            ->postJson("/api/v1/admin/payroll/deductions/{$deduction->id}/approve")
            ->assertOk();

        $deduction->refresh();
        $this->assertSame('approved', $deduction->approval_status);
        $this->assertSame($approver->id, $deduction->approved_by);
        $this->assertNotNull($deduction->approved_at);

        $record->refresh();
        $this->assertEquals(2500, (float) $record->total_deductions);
    }

    public function test_reject_requires_reason_and_excludes_deduction_from_payroll()
    {
        $record = $this->seedPayrollRecord();

        $deduction = PayrollDeduction::create([
            'payroll_record_id' => $record->id,
            'type' => 'other',
            'label' => 'Pending fine',
            'amount' => 2500,
            'approval_status' => 'pending',
            'is_auto' => false,
        ]);

        $reviewer = $this->userWith('Payroll Deduction Reject');
        $this->actingAsUser($reviewer)
            ->postJson("/api/v1/admin/payroll/deductions/{$deduction->id}/reject")
            ->assertStatus(422)
            ->assertJsonValidationErrors('rejection_reason');

        $this->actingAsUser($reviewer)
            ->postJson("/api/v1/admin/payroll/deductions/{$deduction->id}/reject", [
                'rejection_reason' => 'Not supported by documents',
            ])
            ->assertOk();

        $deduction->refresh();
        $this->assertSame('rejected', $deduction->approval_status);
        $this->assertSame('Not supported by documents', $deduction->rejection_reason);

        $record->refresh();
        $this->assertEquals(0, (float) $record->total_deductions);
    }

    public function test_approve_cannot_double_approve()
    {
        $record = $this->seedPayrollRecord();

        $deduction = PayrollDeduction::create([
            'payroll_record_id' => $record->id,
            'type' => 'other',
            'label' => 'Already approved',
            'amount' => 1000,
            'approval_status' => 'approved',
            'is_auto' => false,
        ]);

        $approver = $this->userWith('Payroll Deduction Approve');
        $this->actingAsUser($approver)
            ->postJson("/api/v1/admin/payroll/deductions/{$deduction->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'This deduction is already approved.');
    }

    public function test_store_creates_manual_deductions_as_pending_and_keeps_approved_total()
    {
        $record = $this->seedPayrollRecord();

        $approved = PayrollDeduction::create([
            'payroll_record_id' => $record->id, 'type' => 'loan', 'label' => 'Existing approved loan',
            'amount' => 4000, 'approval_status' => 'approved', 'is_auto' => true,
        ]);
        $record->update(['total_deductions' => 4000]);

        $editor = $this->userWith('Payroll Deduction Create');
        $this->actingAsUser($editor)
            ->postJson("/api/v1/admin/payroll/{$record->id}/deductions", [
                'deductions' => [
                    ['type' => 'unauthorized', 'label' => 'Unauthorized absence', 'amount' => 1200],
                    ['type' => 'other', 'label' => 'Late penalty', 'amount' => 300],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame('approved', $approved->fresh()->approval_status, 'approved deductions must be preserved');

        $new = PayrollDeduction::where('payroll_record_id', $record->id)
            ->where('is_auto', false)
            ->get();

        $this->assertCount(2, $new);
        foreach ($new as $d) {
            $this->assertSame('pending', $d->approval_status, 'manual deductions must be pending');
        }

        // pending rows must NOT affect net pay
        $record->refresh();
        $this->assertEquals(4000, (float) $record->total_deductions);
        $this->assertEquals(120000 - 6000 - 4000, (float) $record->net);
    }

    public function test_report_returns_paginated_rows_with_summary_breakdown()
    {
        $record = $this->seedPayrollRecord();

        PayrollDeduction::create([
            'payroll_record_id' => $record->id, 'type' => 'loan', 'label' => 'Loan A',
            'amount' => 5000, 'approval_status' => 'approved', 'is_auto' => false,
        ]);
        PayrollDeduction::create([
            'payroll_record_id' => $record->id, 'type' => 'unauthorized', 'label' => 'Absence',
            'amount' => 1500, 'approval_status' => 'rejected', 'is_auto' => false,
        ]);

        $reporter = $this->userWith('Payroll Deduction Report');
        $reporter->givePermissionTo(
            Permission::firstOrCreate([
                'name' => 'Payroll Deduction Index',
                'group_name' => 'Payroll Management Permissions',
                'guard_name' => 'api',
            ])
        );

        $this->actingAsUser($reporter)
            ->getJson('/api/v1/admin/payroll/deductions/report?approval_status=approved')
            ->assertOk()
            ->assertJsonPath('summary.by_status.approved.count', 1)
            ->assertJsonPath('summary.by_status.approved.total', 5000)
            ->assertJsonPath('summary.by_status.rejected.count', 0)
            ->assertJsonCount(1, 'summary.by_type')
            ->assertJsonPath('summary.by_type.0.type', 'loan')
            ->assertJsonPath('summary.by_type.0.count', 1)
            ->assertJsonPath('summary.by_type.0.total', 5000)
            ->assertJsonPath('data.total', 1);

        // month filter should match only the seeded record's month
        $this->actingAsUser($reporter)
            ->getJson('/api/v1/admin/payroll/deductions/report?month=1999-01')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_report_csv_returns_a_csv_stream()
    {
        $record = $this->seedPayrollRecord();
        PayrollDeduction::create([
            'payroll_record_id' => $record->id, 'type' => 'other', 'label' => 'Fine',
            'amount' => 250, 'approval_status' => 'pending', 'is_auto' => false,
        ]);

        $reporter = $this->userWith('Payroll Deduction Report');
        $reporter->givePermissionTo(
            Permission::firstOrCreate([
                'name' => 'Payroll Deduction Index',
                'group_name' => 'Payroll Management Permissions',
                'guard_name' => 'api',
            ])
        );

        $response = $this->actingAsUser($reporter)
            ->get('/api/v1/admin/payroll/deductions/report/csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Employee', $csv);
        $this->assertStringContainsString('Fine', $csv);
    }

    public function test_report_is_rejected_for_users_without_the_report_permission()
    {
        $this->seedPayrollRecord();

        $approver = $this->userWith('Payroll Deduction Approve');
        $approver->givePermissionTo(
            Permission::firstOrCreate([
                'name' => 'Payroll Deduction Index',
                'group_name' => 'Payroll Management Permissions',
                'guard_name' => 'api',
            ])
        );

        $this->actingAsUser($approver)
            ->getJson('/api/v1/admin/payroll/deductions/report')
            ->assertForbidden();

        $this->actingAsUser($approver)
            ->getJson('/api/v1/admin/payroll/deductions/summary')
            ->assertForbidden();
    }

    public function test_bulk_approve_only_processes_pending_deductions()
    {
        $record = $this->seedPayrollRecord();

        $pendingA = PayrollDeduction::create([
            'payroll_record_id' => $record->id, 'type' => 'other', 'label' => 'A',
            'amount' => 1000, 'approval_status' => 'pending', 'is_auto' => false,
        ]);
        $pendingB = PayrollDeduction::create([
            'payroll_record_id' => $record->id, 'type' => 'other', 'label' => 'B',
            'amount' => 2000, 'approval_status' => 'pending', 'is_auto' => false,
        ]);
        $approved = PayrollDeduction::create([
            'payroll_record_id' => $record->id, 'type' => 'other', 'label' => 'C',
            'amount' => 5000, 'approval_status' => 'approved', 'is_auto' => false,
        ]);

        $approver = $this->userWith('Payroll Deduction Approve');
        $this->actingAsUser($approver)
            ->postJson("/api/v1/admin/payroll/{$record->id}/deductions/bulk-approve", [
                'deduction_ids' => [$pendingA->id, $pendingB->id, $approved->id],
            ])
            ->assertOk();

        $this->assertSame('approved', $pendingA->fresh()->approval_status);
        $this->assertSame('approved', $pendingB->fresh()->approval_status);

        $record->refresh();
        $this->assertEquals(8000, (float) $record->total_deductions);
    }
}
