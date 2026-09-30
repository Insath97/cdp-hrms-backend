<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Employee;
use App\Models\PayrollRecord;
use App\Models\PurposeCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CusReportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(User $user): self
    {
        Auth::guard('web')->setUser($user);
        $token = auth('api')->login($user);

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

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

    private function makeAdmin(string $username): User
    {
        $user = $this->makeUser('Payroll Admin', $username);
        // The CUS report is gated by payroll month activation; the manage
        // permission bypasses the gate (PayrollActivationService::canView).
        $user->givePermissionTo(
            \Spatie\Permission\Models\Permission::firstOrCreate([
                'name' => 'Payroll Activate',
                'group_name' => 'Payroll Management Permissions',
                'guard_name' => 'api',
            ])
        );

        return $user;
    }

    private function commercialBank(): Bank
    {
        return Bank::create([
            'name' => 'Commercial Bank',
            'swift_code' => null,
            'account_number_length' => 10,
            'account_number_format' => '10 digits for any account number',
            'is_commercial' => true,
            'is_active' => true,
        ]);
    }

    private function otherBank(): Bank
    {
        return Bank::create([
            'name' => 'Sampath Bank',
            'swift_code' => 'BSAMLKLX001',
            'account_number_length' => 12,
            'account_number_format' => '12 digits for any account number, Ex: 10xxxxxxxxxx',
            'is_commercial' => false,
            'is_active' => true,
        ]);
    }

    private int $seq = 0;

    private function seedProcessedRecord(
        Bank $bank,
        string $payDay = 'basic',
        string $status = 'processed',
        float $net = 50000.00,
        string $employeeCode = 'E-0001',
        ?string $accountNumber = null
    ): PayrollRecord {
        $user = $this->makeUser('Employee ' . $employeeCode, strtolower(str_replace('-', '', $employeeCode)) . uniqid());
        $this->seq++;
        $seq = $this->seq;

        // The employees table has no user_id column; a payroll record points
        // at its employee through payroll_records.employee_id.
        $employee = Employee::create([
            'full_name' => 'Test Employee',
            'employee_code' => $employeeCode,
            'id_number' => '1990' . str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
            'date_of_birth' => '1990-01-15',
            'phone_primary' => '0770000001',
            'joined_at' => '2024-01-01',
            'bank_id' => $bank->id,
            'bank_name' => $bank->name,
            'account_number' => $accountNumber ?? str_pad('12345678', $bank->account_number_length ?? 10, '9', STR_PAD_RIGHT),
        ]);

        return PayrollRecord::create([
            'user_id' => $user->id,
            'employee_id' => $employee->id,
            'month' => '2026-08',
            'pay_day' => $payDay,
            'basic' => 60000,
            'allowances' => 0,
            'epf_employee' => 0,
            'achievement_percentage' => 0,
            'payment_percentage' => 100,
            'how_much_paid' => $net,
            'total_deductions' => 0,
            'net' => $net,
            'status' => $status,
        ]);
    }

    public function test_preview_returns_only_processed_records_for_selected_cycle()
    {
        $bank = $this->commercialBank();

        // Processed 5th + 20th, plus an unprocessed 5th that must be excluded.
        $this->seedProcessedRecord($bank, 'basic', 'processed', 60000.00, 'E-0001');
        $this->seedProcessedRecord($bank, 'allowance', 'processed', 20000.00, 'E-0002');
        $this->seedProcessedRecord($bank, 'basic', 'draft', 99999.00, 'E-0003');

        $user = $this->makeAdmin('cus.admin');

        // 5th (basic) only: the draft record is excluded.
        $this->actingAsUser($user)
            ->getJson('/api/v1/reports/cus/preview?month=2026-08&pay_day=basic&bank=commercial')
            ->assertOk()
            ->assertJsonPath('data.pay_day_label', '5th')
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.rows.0.type', '1')
            ->assertJsonPath('data.rows.0.to_account', '1234567899')
            ->assertJsonPath('data.rows.0.amount', '60000.00')
            ->assertJsonPath('data.rows.0.swift_code', '')
            ->assertJsonPath('data.sender_description', 'Company Salaries August 2026')
            ->assertJsonPath('data.purpose_code', '784001');

        // 20th (allowance) only.
        $this->actingAsUser($user)
            ->getJson('/api/v1/reports/cus/preview?month=2026-08&pay_day=allowance&bank=commercial')
            ->assertOk()
            ->assertJsonPath('data.pay_day_label', '20th')
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.total_amount', '20000.00');
    }

    public function test_commercial_filter_excludes_other_banks()
    {
        $commercial = $this->commercialBank();
        $other = $this->otherBank();

        $this->seedProcessedRecord($commercial, 'basic', 'processed', 60000.00, 'E-0001');
        $this->seedProcessedRecord($other, 'basic', 'processed', 70000.00, 'E-0002');

        $user = $this->makeAdmin('cus.admin2');

        $this->actingAsUser($user)
            ->getJson('/api/v1/reports/cus/preview?month=2026-08&pay_day=basic&bank=commercial')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.rows.0.bank_name', 'Commercial Bank');
    }

    public function test_other_bank_rows_use_type_two_and_swift_code()
    {
        $other = $this->otherBank();
        $this->seedProcessedRecord($other, 'basic', 'processed', 70000.00, 'E-0002');

        $user = $this->makeAdmin('cus.admin3');

        $this->actingAsUser($user)
            ->getJson('/api/v1/reports/cus/preview?month=2026-08&pay_day=basic&bank=other')
            ->assertOk()
            ->assertJsonPath('data.rows.0.type', '2')
            ->assertJsonPath('data.rows.0.swift_code', 'BSAMLKLX001');
    }

    public function test_purpose_code_selects_description()
    {
        $bank = $this->commercialBank();
        $this->seedProcessedRecord($bank, 'basic', 'processed', 60000.00, 'E-0001');

        PurposeCode::create(['code' => '788004', 'description' => 'Credit Card Payments', 'is_active' => true]);
        PurposeCode::create(['code' => '784001', 'description' => 'Consultancy Fees, Legal Charges and Salaries', 'is_default' => true, 'is_active' => true]);

        $user = $this->makeAdmin('cus.admin4');

        $this->actingAsUser($user)
            ->getJson('/api/v1/reports/cus/preview?month=2026-08&pay_day=basic&bank=commercial&purpose_code=788004')
            ->assertOk()
            ->assertJsonPath('data.purpose_code', '788004')
            ->assertJsonPath('data.purpose_description', 'Credit Card Payments');
    }

    public function test_employees_missing_account_number_are_skipped()
    {
        $bank = $this->commercialBank();
        $user = $this->makeUser('No Account', 'cus.noaccount');

        $employee = Employee::create([
            'user_id' => $user->id,
            'full_name' => 'No Account Person',
            'employee_code' => 'E-0900',
            'id_number' => '199012345679',
            'date_of_birth' => '1990-01-15',
            'phone_primary' => '0770000009',
            'joined_at' => '2024-01-01',
            'bank_id' => $bank->id,
            'bank_name' => $bank->name,
            'account_number' => null,
        ]);

        PayrollRecord::create([
            'user_id' => $user->id,
            'employee_id' => $employee->id,
            'month' => '2026-08',
            'pay_day' => 'basic',
            'basic' => 60000,
            'allowances' => 0,
            'epf_employee' => 0,
            'achievement_percentage' => 0,
            'payment_percentage' => 100,
            'how_much_paid' => 60000,
            'total_deductions' => 0,
            'net' => 60000,
            'status' => 'processed',
        ]);

        $admin = $this->makeAdmin('cus.admin5');

        $this->actingAsUser($admin)
            ->getJson('/api/v1/reports/cus/preview?month=2026-08&pay_day=basic&bank=commercial')
            ->assertOk()
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.skipped', 1);
    }

    public function test_preview_validates_pay_day()
    {
        $admin = $this->makeAdmin('cus.admin6');

        $this->actingAsUser($admin)
            ->getJson('/api/v1/reports/cus/preview?month=2026-08&pay_day=nonsense&bank=commercial')
            ->assertStatus(422);
    }

    public function test_download_returns_bank_formatted_csv()
    {
        $bank = $this->commercialBank();
        $this->seedProcessedRecord($bank, 'basic', 'processed', 60000.00, 'E-0001');
        PurposeCode::create(['code' => '784001', 'description' => 'Consultancy Fees, Legal Charges and Salaries', 'is_default' => true]);

        $admin = $this->makeAdmin('cus.admin7');

        $response = $this->actingAsUser($admin)
            ->get('/api/v1/reports/cus/download?month=2026-08&pay_day=basic&bank=commercial');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Type', $csv);
        $this->assertStringContainsString('To account', $csv);
        $this->assertStringContainsString('Company Salaries August 2026', $csv);
        $this->assertStringContainsString('1234567899', $csv);
        $this->assertStringContainsString('60000.00', $csv);
        $this->assertStringContainsString('784001', $csv);
    }

    public function test_bank_lookup_endpoint_returns_swift_codes()
    {
        $this->commercialBank();
        $this->otherBank();

        $admin = $this->makeAdmin('cus.admin8');

        $this->actingAsUser($admin)
            ->getJson('/api/v1/lookups/banks')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Sampath Bank', 'swift_code' => 'BSAMLKLX001']);
    }

    public function test_employees_with_wrong_bank_account_length_are_skipped()
    {
        // Sampath Bank requires 12 digits; 10 digits must not reach the bank file.
        $bank = $this->otherBank();
        $this->seedProcessedRecord($bank, 'basic', 'processed', 60000.00, 'E-0001', '0012345678');

        $admin = $this->makeAdmin('cus.admin10');

        $this->actingAsUser($admin)
            ->getJson('/api/v1/reports/cus/preview?month=2026-08&pay_day=basic&bank=other')
            ->assertOk()
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.skipped', 1);
    }

    public function test_download_is_blocked_for_unactivated_month()
    {
        $bank = $this->commercialBank();
        $this->seedProcessedRecord($bank, 'basic', 'processed', 60000.00, 'E-0001');

        // No "Payroll Activate" permission => the activation gate applies and
        // the month defaults to inactive, so both endpoints must refuse.
        $user = $this->makeUser('Payroll Admin', 'cus.admin9');

        $this->actingAsUser($user)
            ->getJson('/api/v1/reports/cus/download?month=2026-08&pay_day=basic&bank=commercial')
            ->assertStatus(403);

        $this->actingAsUser($user)
            ->getJson('/api/v1/reports/cus/preview?month=2026-08&pay_day=basic&bank=commercial')
            ->assertStatus(403);
    }
}
