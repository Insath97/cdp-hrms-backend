<?php

namespace Database\Seeders;

use App\Models\PurposeCode;
use Illuminate\Database\Seeder;

class PurposeCodeSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            ['401003', 'Travel, Tourism, Medical, Education, Training & Other', false],
            ['740003', 'Financial Services - Any Other Financial Activity', false],
            ['750002', 'Other Health Services, Insurance Non-Related', false],
            ['784001', 'Consultancy Fees, Legal Charges and Salaries', true],
            ['787008', 'EDUCATION OTHER', false],
            ['788004', 'Credit Card Payments', false],
            ['999001', 'Other Capital Financial Transactions', false],
        ];

        foreach ($codes as [$code, $description, $isDefault]) {
            PurposeCode::updateOrCreate(
                ['code' => $code],
                [
                    'description' => $description,
                    'is_default' => $isDefault,
                    'is_active' => true,
                ]
            );
        }
    }
}
