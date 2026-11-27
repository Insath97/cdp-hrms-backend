<?php

namespace Database\Seeders;

use App\Models\LeaveEntitlementRule;
use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveEntitlementSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure core leave types exist (new schema)
        $annual = LeaveType::updateOrCreate(
            ['code' => 'AL'],
            [
                'name' => 'Annual Leave',
                'category' => 'annual',
                'calculation_unit' => 'days',
                'default_allocation' => 0,
                'is_paid' => true,
                'is_active' => true,
                'description' => 'Annual leave entitlement based on join date and calendar year.',
            ]
        );

        $casual = LeaveType::updateOrCreate(
            ['code' => 'CL'],
            [
                'name' => 'Casual Leave',
                'category' => 'casual',
                'calculation_unit' => 'days',
                'default_allocation' => 0,
                'is_paid' => true,
                'is_active' => true,
                'description' => 'Casual leave entitlement based on join date and calendar year.',
            ]
        );

        // Short Leave: 2 per month, 1.5 hours each (tracked as 36 hours/year)
        LeaveType::updateOrCreate(
            ['code' => 'SL'],
            [
                'name' => 'Short Leave',
                'category' => 'other',
                'calculation_unit' => 'hours',
                'default_allocation' => 36,
                'is_paid' => true,
                'is_active' => true,
                'description' => '2 short leaves per month. Duration: 1.5 hours per short leave (36 hours per year).',
                'requires_medical_certificate' => false,
                'is_pregnancy_related' => false,
            ]
        );

        // Reset rules
        LeaveEntitlementRule::query()->delete();

        $rules = [
            // Annual - 1st calendar year: none
            ['category' => 'annual', 'service_year_from' => 1, 'service_year_to' => 1, 'join_month_from' => null, 'join_month_to' => null, 'entitlement_days' => 0, 'accrual_type' => 'grant', 'max_days' => null],
            // Annual - 2nd calendar year by join quarter (of join year)
            ['category' => 'annual', 'service_year_from' => 2, 'service_year_to' => 2, 'join_month_from' => 1, 'join_month_to' => 3, 'entitlement_days' => 14, 'accrual_type' => 'grant', 'max_days' => null],
            ['category' => 'annual', 'service_year_from' => 2, 'service_year_to' => 2, 'join_month_from' => 4, 'join_month_to' => 6, 'entitlement_days' => 10, 'accrual_type' => 'grant', 'max_days' => null],
            ['category' => 'annual', 'service_year_from' => 2, 'service_year_to' => 2, 'join_month_from' => 7, 'join_month_to' => 9, 'entitlement_days' => 7,  'accrual_type' => 'grant', 'max_days' => null],
            ['category' => 'annual', 'service_year_from' => 2, 'service_year_to' => 2, 'join_month_from' => 10, 'join_month_to' => 12, 'entitlement_days' => 4, 'accrual_type' => 'grant', 'max_days' => null],
            // Annual - 3rd calendar year onwards
            ['category' => 'annual', 'service_year_from' => 3, 'service_year_to' => null, 'join_month_from' => null, 'join_month_to' => null, 'entitlement_days' => 14, 'accrual_type' => 'grant', 'max_days' => null],

            // Casual - 1st calendar year: 1 day per completed month, cap 12
            ['category' => 'casual', 'service_year_from' => 1, 'service_year_to' => 1, 'join_month_from' => null, 'join_month_to' => null, 'entitlement_days' => 1, 'accrual_type' => 'monthly_on_completion', 'max_days' => 12],
            // Casual - 2nd calendar year onwards
            ['category' => 'casual', 'service_year_from' => 2, 'service_year_to' => null, 'join_month_from' => null, 'join_month_to' => null, 'entitlement_days' => 7, 'accrual_type' => 'grant', 'max_days' => null],
        ];

        foreach ($rules as $rule) {
            LeaveEntitlementRule::create($rule + ['is_active' => true]);
        }
    }
}
