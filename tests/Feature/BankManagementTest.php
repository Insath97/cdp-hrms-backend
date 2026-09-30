<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Employee;
use App\Models\PurposeCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BankManagementTest extends TestCase
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

    /**
     * A user holding every Bank / PurposeCode management permission.
     */
    private function makeManager(string $username): User
    {
        $user = $this->makeUser('Bank Manager', $username);
        $user->assignRole(Role::findOrCreate('tester', 'api'));

        foreach ([
            'Bank Index', 'Bank Create', 'Bank Update', 'Bank Delete', 'Bank Toggle Status',
            'PurposeCode Index', 'PurposeCode Create', 'PurposeCode Update',
            'PurposeCode Delete', 'PurposeCode Toggle Status',
        ] as $permission) {
            $user->givePermissionTo(
                Permission::firstOrCreate([
                    'name' => $permission,
                    'group_name' => 'Bank Management Permissions',
                    'guard_name' => 'api',
                ])
            );
        }

        return $user;
    }

    /**
     * The FormRequests override failedValidation() and return
     * { message, errors: [{ field, messages }] }, so the built-in
     * assertJsonValidationErrors() helper cannot be used here.
     */
    private function assertHasValidationErrors($response, array $fields): void
    {
        $actual = collect($response->json('errors', []))->pluck('field')->all();

        foreach ($fields as $field) {
            $this->assertContains(
                $field,
                $actual,
                "Expected a validation error for [{$field}]. Got: " . json_encode($response->json('errors'))
            );
        }
    }

    private function bankPayload(array $overrides = []): array    {
        return array_merge([
            'name' => 'Sampath Bank',
            'swift_code' => 'BSAMLKLX001',
            'account_number_length' => 12,
            'account_number_format' => '12 digits for any account number',
            'account_number_example' => '109876543210',
            'is_commercial' => false,
            'is_active' => true,
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /* Banks                                                               */
    /* ------------------------------------------------------------------ */

    public function test_bank_index_requires_permission()
    {
        $user = $this->makeUser('No Perms', 'bank.noperm');

        $this->actingAsUser($user)
            ->getJson('/api/v1/banks')
            ->assertForbidden();
    }

    public function test_bank_index_paginates_and_filters()
    {
        Bank::create($this->bankPayload(['name' => 'Amana Bank', 'swift_code' => 'BAMANALKL']));
        Bank::create($this->bankPayload(['name' => 'Commercial Bank', 'swift_code' => null, 'is_commercial' => true, 'account_number_length' => 10]));

        $manager = $this->makeManager('bank.index');

        $this->actingAsUser($manager)
            ->getJson('/api/v1/banks?search=Amana')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonFragment(['name' => 'Amana Bank']);

        $this->actingAsUser($manager)
            ->getJson('/api/v1/banks?is_commercial=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonFragment(['name' => 'Commercial Bank']);
    }

    public function test_bank_can_be_created()
    {
        $manager = $this->makeManager('bank.create');

        $this->actingAsUser($manager)
            ->postJson('/api/v1/banks', $this->bankPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sampath Bank')
            ->assertJsonPath('data.swift_code', 'BSAMLKLX001')
            ->assertJsonPath('data.account_number_length', 12)
            ->assertJsonPath('data.is_commercial', false);

        $this->assertDatabaseHas('banks', ['name' => 'Sampath Bank']);
    }

    public function test_bank_creation_validates_input()
    {
        Bank::create($this->bankPayload());

        $manager = $this->makeManager('bank.create.dup');

        // Duplicate name, malformed SWIFT, out-of-range length.
        $this->actingAsUser($manager)
            ->postJson('/api/v1/banks', $this->bankPayload(['swift_code' => 'bad', 'account_number_length' => 99]))
            ->assertStatus(422);
        $this->assertHasValidationErrors(
            $this->actingAsUser($manager)
                ->postJson('/api/v1/banks', $this->bankPayload(['swift_code' => 'bad', 'account_number_length' => 99])),
            ['name', 'swift_code', 'account_number_length']
        );
    }

    public function test_bank_can_be_shown()
    {
        $bank = Bank::create($this->bankPayload());

        $manager = $this->makeManager('bank.show');

        $this->actingAsUser($manager)
            ->getJson("/api/v1/banks/{$bank->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $bank->id)
            ->assertJsonPath('data.swift_code', 'BSAMLKLX001');
    }

    public function test_bank_show_returns_404_for_missing_bank()
    {
        $manager = $this->makeManager('bank.show.404');

        $this->actingAsUser($manager)
            ->getJson('/api/v1/banks/999999')
            ->assertNotFound();
    }

    public function test_bank_can_be_updated_and_syncs_employee_bank_name()
    {
        $bank = Bank::create($this->bankPayload(['name' => 'Old Name']));

        Employee::create([
            'full_name' => 'Test Employee',
            'employee_code' => 'E-1001',
            'id_number' => '199011112222',
            'date_of_birth' => '1990-11-11',
            'phone_primary' => '0770000001',
            'joined_at' => '2024-01-01',
            'bank_id' => $bank->id,
            'bank_name' => 'Old Name',
            'account_number' => '109876543210',
        ]);

        $manager = $this->makeManager('bank.update');

        $this->actingAsUser($manager)
            ->patchJson("/api/v1/banks/{$bank->id}", ['name' => 'Renamed Bank', 'account_number_length' => 11])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Bank')
            ->assertJsonPath('data.account_number_length', 11);

        // The denormalised bank_name on employees must follow the rename.
        $this->assertDatabaseHas('employees', [
            'bank_id' => $bank->id,
            'bank_name' => 'Renamed Bank',
        ]);
    }

    public function test_bank_update_rejects_duplicate_name()
    {
        Bank::create($this->bankPayload(['name' => 'Amana Bank']));
        $bank = Bank::create($this->bankPayload(['name' => 'Sampath Bank']));

        $manager = $this->makeManager('bank.update.dup');

        $this->actingAsUser($manager)
            ->patchJson("/api/v1/banks/{$bank->id}", ['name' => 'Amana Bank'])
            ->assertStatus(422);
        $this->assertHasValidationErrors(
            $this->actingAsUser($manager)
                ->patchJson("/api/v1/banks/{$bank->id}", ['name' => 'Amana Bank']),
            ['name']
        );
    }

    public function test_bank_status_can_be_toggled()
    {
        $bank = Bank::create($this->bankPayload());

        $manager = $this->makeManager('bank.toggle');

        $this->actingAsUser($manager)
            ->patchJson("/api/v1/banks/{$bank->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('banks', ['id' => $bank->id, 'is_active' => false]);
    }

    public function test_bank_in_use_cannot_be_deleted()
    {
        $bank = Bank::create($this->bankPayload());

        Employee::create([
            'full_name' => 'Test Employee',
            'employee_code' => 'E-2001',
            'id_number' => '199022223333',
            'date_of_birth' => '1990-02-22',
            'phone_primary' => '0770000002',
            'joined_at' => '2024-01-01',
            'bank_id' => $bank->id,
            'bank_name' => $bank->name,
            'account_number' => '109876543210',
        ]);

        $manager = $this->makeManager('bank.delete.inuse');

        $this->actingAsUser($manager)
            ->deleteJson("/api/v1/banks/{$bank->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('banks', ['id' => $bank->id]);
    }

    public function test_unused_bank_can_be_deleted()
    {
        $bank = Bank::create($this->bankPayload());

        $manager = $this->makeManager('bank.delete');

        $this->actingAsUser($manager)
            ->deleteJson("/api/v1/banks/{$bank->id}")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('banks', ['id' => $bank->id]);
    }

    /* ------------------------------------------------------------------ */
    /* Purpose codes                                                       */
    /* ------------------------------------------------------------------ */

    public function test_purpose_code_can_be_created()
    {
        $manager = $this->makeManager('pc.create');

        $this->actingAsUser($manager)
            ->postJson('/api/v1/purpose-codes', [
                'code' => '788004',
                'description' => 'Credit Card Payments',
                'is_default' => false,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', '788004')
            ->assertJsonPath('data.description', 'Credit Card Payments');

        $this->assertDatabaseHas('purpose_codes', ['code' => '788004']);
    }

    public function test_purpose_code_creation_validates_six_digit_code()
    {
        $manager = $this->makeManager('pc.create.invalid');

        $this->actingAsUser($manager)
            ->postJson('/api/v1/purpose-codes', [
                'code' => '123',
                'description' => 'Bad code',
            ])
            ->assertStatus(422);
        $this->assertHasValidationErrors(
            $this->actingAsUser($manager)
                ->postJson('/api/v1/purpose-codes', ['code' => '123', 'description' => 'Bad code']),
            ['code']
        );
    }

    public function test_creating_a_default_purpose_code_clears_the_previous_one()
    {
        $old = PurposeCode::create([
            'code' => '784001',
            'description' => 'Consultancy Fees, Legal Charges and Salaries',
            'is_default' => true,
            'is_active' => true,
        ]);

        $manager = $this->makeManager('pc.create.default');

        $this->actingAsUser($manager)
            ->postJson('/api/v1/purpose-codes', [
                'code' => '788004',
                'description' => 'Credit Card Payments',
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);

        // Exactly one default may exist at any time.
        $this->assertDatabaseHas('purpose_codes', ['code' => '788004', 'is_default' => true]);
        $this->assertDatabaseHas('purpose_codes', ['id' => $old->id, 'is_default' => false]);
        $this->assertSame(1, PurposeCode::where('is_default', true)->count());
    }

    public function test_purpose_code_index_filters_and_searches()
    {
        PurposeCode::create(['code' => '784001', 'description' => 'Salaries', 'is_default' => true, 'is_active' => true]);
        PurposeCode::create(['code' => '788004', 'description' => 'Credit Card Payments', 'is_default' => false, 'is_active' => true]);

        $manager = $this->makeManager('pc.index');

        $this->actingAsUser($manager)
            ->getJson('/api/v1/purpose-codes?search=Credit')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonFragment(['code' => '788004']);

        $this->actingAsUser($manager)
            ->getJson('/api/v1/purpose-codes?is_default=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonFragment(['code' => '784001']);
    }

    public function test_purpose_code_can_be_shown_and_updated()
    {
        $purposeCode = PurposeCode::create([
            'code' => '788004',
            'description' => 'Credit Card Payments',
            'is_default' => false,
            'is_active' => true,
        ]);

        $manager = $this->makeManager('pc.update');

        $this->actingAsUser($manager)
            ->getJson("/api/v1/purpose-codes/{$purposeCode->id}")
            ->assertOk()
            ->assertJsonPath('data.code', '788004');

        $this->actingAsUser($manager)
            ->patchJson("/api/v1/purpose-codes/{$purposeCode->id}", [
                'description' => 'Credit Card Settlements',
            ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Credit Card Settlements');
    }

    public function test_setting_a_new_default_clears_the_previous_one()
    {
        $old = PurposeCode::create([
            'code' => '784001',
            'description' => 'Salaries',
            'is_default' => true,
            'is_active' => true,
        ]);
        $new = PurposeCode::create([
            'code' => '788004',
            'description' => 'Credit Card Payments',
            'is_default' => false,
            'is_active' => true,
        ]);

        $manager = $this->makeManager('pc.update.default');

        $this->actingAsUser($manager)
            ->patchJson("/api/v1/purpose-codes/{$new->id}", ['is_default' => true])
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('purpose_codes', ['id' => $new->id, 'is_default' => true]);
        $this->assertDatabaseHas('purpose_codes', ['id' => $old->id, 'is_default' => false]);
    }

    public function test_default_purpose_code_cannot_be_deleted_or_deactivated()
    {
        $purposeCode = PurposeCode::create([
            'code' => '784001',
            'description' => 'Salaries',
            'is_default' => true,
            'is_active' => true,
        ]);

        $manager = $this->makeManager('pc.delete.default');

        $this->actingAsUser($manager)
            ->deleteJson("/api/v1/purpose-codes/{$purposeCode->id}")
            ->assertStatus(422);

        $this->actingAsUser($manager)
            ->patchJson("/api/v1/purpose-codes/{$purposeCode->id}/toggle-status")
            ->assertStatus(422);

        $this->assertDatabaseHas('purpose_codes', ['id' => $purposeCode->id, 'is_active' => true]);
    }

    public function test_non_default_purpose_code_can_be_deleted()
    {
        $purposeCode = PurposeCode::create([
            'code' => '788004',
            'description' => 'Credit Card Payments',
            'is_default' => false,
            'is_active' => true,
        ]);

        $manager = $this->makeManager('pc.delete');

        $this->actingAsUser($manager)
            ->deleteJson("/api/v1/purpose-codes/{$purposeCode->id}")
            ->assertOk();

        $this->assertDatabaseMissing('purpose_codes', ['id' => $purposeCode->id]);
    }

    public function test_purpose_code_status_can_be_toggled()
    {
        $purposeCode = PurposeCode::create([
            'code' => '788004',
            'description' => 'Credit Card Payments',
            'is_default' => false,
            'is_active' => true,
        ]);

        $manager = $this->makeManager('pc.toggle');

        $this->actingAsUser($manager)
            ->patchJson("/api/v1/purpose-codes/{$purposeCode->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }
}
