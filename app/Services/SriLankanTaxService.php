<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SriLankanTaxService
{
    /**
     * PAYE on monthly regular profits from employment (Summary Tax Table).
     *
     * 1. Up to Rs. 150,000            -> relief from tax
     * 2. 150,000 - 233,333            -> 6%  less Rs. 9,000
     * 3. 233,333 - 275,000            -> 18% less Rs. 37,000
     * 4. 275,000 - 316,667            -> 24% less Rs. 53,500
     * 5. 316,667 - 358,333            -> 30% less Rs. 72,500
     * 6. Over Rs. 358,333             -> 36% less Rs. 94,000
     */
    public static function paye(float $monthlyTaxableIncome): float
    {
        if ($monthlyTaxableIncome <= 150000) {
            $tax = 0.0;
            $calculationMethod = 'Exempt (Income <= 150,000: Tax 0%)';
        } elseif ($monthlyTaxableIncome <= 233333) {
            $tax = round($monthlyTaxableIncome * 0.06 - 9000, 2);
            $calculationMethod = 'Tier 1 (Income 150,000 - 233,333: 6% - 9,000)';
        } elseif ($monthlyTaxableIncome <= 275000) {
            $tax = round($monthlyTaxableIncome * 0.18 - 37000, 2);
            $calculationMethod = 'Tier 2 (Income 233,333 - 275,000: 18% - 37,000)';
        } elseif ($monthlyTaxableIncome <= 316667) {
            $tax = round($monthlyTaxableIncome * 0.24 - 53500, 2);
            $calculationMethod = 'Tier 3 (Income 275,000 - 316,667: 24% - 53,500)';
        } elseif ($monthlyTaxableIncome <= 358333) {
            $tax = round($monthlyTaxableIncome * 0.30 - 72500, 2);
            $calculationMethod = 'Tier 4 (Income 316,667 - 358,333: 30% - 72,500)';
        } else {
            $tax = round($monthlyTaxableIncome * 0.36 - 94000, 2);
            $calculationMethod = 'Tier 5 (Income > 358,333: 36% - 94,000)';
        }

        Log::info('PAYE tax calculated', [
            'monthly_taxable_income' => $monthlyTaxableIncome,
            'calculation_method'     => $calculationMethod,
            'paye_tax'               => $tax,
        ]);

        return $tax;
    }

    /**
     * Employee EPF contribution (8% of gross earnings).
     */
    public static function epfEmployee(float $gross): float
    {
        return round($gross * 0.08, 2);
    }
}
