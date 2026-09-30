<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers 2026_09_29_100003_remap_employee_bank_names.
 *
 * The migration runs as part of RefreshDatabase before the test body, so the
 * behaviour is asserted by invoking the same file again after seeding.
 */
class EmployeeBankNameBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::create([
            'name' => 'Ops',
            'code' => 'OPS',
            'is_active' => true,
        ]);
    }

    private function makeBank(string $name, bool $commercial = false): Bank
    {
        return Bank::create([
            'name' => $name,
            'swift_code' => 'TESTCODE1',
            'account_number_length' => 10,
            'is_commercial' => $commercial,
            'is_active' => true,
        ]);
    }

    private function makeEmployee(string $code, string $bankName): Employee
    {
        return Employee::create([
            'full_name' => "Employee {$code}",
            'employee_code' => $code,
            'id_number' => 'ID-' . $code,
            'date_of_birth' => '1990-01-15',
            'phone_primary' => '0770000001',
            'joined_at' => '2024-01-01',
            'department_id' => $this->department->id,
            'bank_name' => $bankName,
        ]);
    }

    private function runBackfill(): void
    {
        $migration = require base_path('database/migrations/2026_09_29_100003_remap_employee_bank_names.php');
        $migration->up();
    }

    /**
     * Every alias variant that appears in the live employee data.
     */
    #[DataProvider('aliasProvider')]
    public function test_it_resolves_dirty_bank_names_to_the_canonical_bank(string $dirty, string $canonical)
    {
        $bank = $this->makeBank($canonical, $canonical === 'Commercial Bank');
        $employee = $this->makeEmployee('E-' . substr(md5($dirty), 0, 6), $dirty);

        $this->assertNull($employee->bank_id);

        $this->runBackfill();

        $this->assertSame($bank->id, $employee->fresh()->bank_id, "Failed to map '{$dirty}'");
    }

    public static function aliasProvider(): array
    {
        return [
            'exact name'              => ['Bank of Ceylon', 'Bank of Ceylon'],
            'uppercase abbreviation'  => ['BOC', 'Bank of Ceylon'],
            'branch suffix'           => ['Boc / Mannar', 'Bank of Ceylon'],
            'branch no space'         => ['Boc /Mannar', 'Bank of Ceylon'],
            'abbreviation plus bank'  => ['Boc Bank', 'Bank of Ceylon'],
            'typo'                    => ['Commecial Bank', 'Commercial Bank'],
            'bare commercial'         => ['Commercial', 'Commercial Bank'],
            'lowercase branch'        => ['commercial / Mannar', 'Commercial Bank'],
            'peoples apostrophe'      => ["People's", "People's Bank"],
            'peoples no apostrophe'   => ['Peoples', "People's Bank"],
            'peoples bank spaced'     => ["PEOPLE 'S BANK", "People's Bank"],
            'nsb abbreviation'        => ['NSB', 'National Savings Bank'],
            'hnb abbreviation'        => ['HNB', 'Hatton National Bank'],
            'sampath abbreviation'    => ['Sampath', 'Sampath Bank'],
            'seylan typo'             => ['Selan Bank / Mannar', 'Seylan Bank'],
        ];
    }

    public function test_it_leaves_unrecognised_values_alone()
    {
        $this->makeBank('Bank of Ceylon');
        // An account number was typed into bank_name; it must NOT be guessed at.
        $employee = $this->makeEmployee('E-JUNK', '204423000780');

        $this->runBackfill();

        $this->assertNull($employee->fresh()->bank_id);
    }

    public function test_it_does_not_overwrite_an_existing_link()
    {
        $boc = $this->makeBank('Bank of Ceylon');
        $commercial = $this->makeBank('Commercial Bank', true);

        $employee = $this->makeEmployee('E-LINKED', 'BOC');
        $employee->update(['bank_id' => $commercial->id]);

        $this->runBackfill();

        $this->assertSame($commercial->id, $employee->fresh()->bank_id);
        $this->assertNotSame($boc->id, $employee->fresh()->bank_id);
    }
}
