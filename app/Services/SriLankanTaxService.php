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
    /**
     * Determine tax bracket breakdown for a given taxable income/package.
     *
     * Summary Tax Table:
     * 1. Up to Rs. 150,000            -> relief from tax (0%)
     * 2. 150,000 - 233,333            -> 6%  less Rs. 9,000
     * 3. 233,333 - 275,000            -> 18% less Rs. 37,000
     * 4. 275,000 - 316,667            -> 24% less Rs. 53,500
     * 5. 316,667 - 358,333            -> 30% less Rs. 72,500
     * 6. Over Rs. 358,333             -> 36% less Rs. 94,000
     */
    public static function calculateBracketDetails(float $amount): array
    {
        if ($amount <= 150000) {
            return [
                'tier'        => 0,
                'rate'        => 0.0,
                'deduction'   => 0.0,
                'description' => 'Exempt (Income <= 150,000: Tax 0%)',
                'formula'     => '0.00',
                'tax'         => 0.0,
            ];
        }

        if ($amount <= 233333) {
            $tax = max(0.0, round($amount * 0.06 - 9000, 2));
            return [
                'tier'        => 1,
                'rate'        => 0.06,
                'deduction'   => 9000.0,
                'description' => 'Tier 1 (Income 150,000 - 233,333: 6% - 9,000)',
                'formula'     => "round({$amount} * 0.06 - 9000, 2)",
                'tax'         => $tax,
            ];
        }

        if ($amount <= 275000) {
            $tax = max(0.0, round($amount * 0.18 - 37000, 2));
            return [
                'tier'        => 2,
                'rate'        => 0.18,
                'deduction'   => 37000.0,
                'description' => 'Tier 2 (Income 233,333 - 275,000: 18% - 37,000)',
                'formula'     => "round({$amount} * 0.18 - 37000, 2)",
                'tax'         => $tax,
            ];
        }

        if ($amount <= 316667) {
            $tax = max(0.0, round($amount * 0.24 - 53500, 2));
            return [
                'tier'        => 3,
                'rate'        => 0.24,
                'deduction'   => 53500.0,
                'description' => 'Tier 3 (Income 275,000 - 316,667: 24% - 53,500)',
                'formula'     => "round({$amount} * 0.24 - 53500, 2)",
                'tax'         => $tax,
            ];
        }

        if ($amount <= 358333) {
            $tax = max(0.0, round($amount * 0.30 - 72500, 2));
            return [
                'tier'        => 4,
                'rate'        => 0.30,
                'deduction'   => 72500.0,
                'description' => 'Tier 4 (Income 316,667 - 358,333: 30% - 72,500)',
                'formula'     => "round({$amount} * 0.30 - 72500, 2)",
                'tax'         => $tax,
            ];
        }

        $tax = max(0.0, round($amount * 0.36 - 94000, 2));
        return [
            'tier'        => 5,
            'rate'        => 0.36,
            'deduction'   => 94000.0,
            'description' => 'Tier 5 (Income > 358,333: 36% - 94,000)',
            'formula'     => "round({$amount} * 0.36 - 94000, 2)",
            'tax'         => $tax,
        ];
    }

    /**
     * PAYE on monthly regular profits from employment.
     */
    public static function paye(float $monthlyTaxableIncome, array $context = []): float
    {
        $bracket = self::calculateBracketDetails($monthlyTaxableIncome);

        Log::info('[PAYE Calculation] Tax evaluated', array_merge([
            'monthly_taxable_income' => $monthlyTaxableIncome,
            'tier'                   => $bracket['tier'],
            'calculation_method'     => $bracket['description'],
            'formula'                => $bracket['formula'],
            'paye_tax'               => $bracket['tax'],
        ], $context));

        return $bracket['tax'];
    }

    /**
     * Employee EPF contribution (8% of gross earnings).
     */
    public static function epfEmployee(float $gross): float
    {
        return round($gross * 0.08, 2);
    }

    /**
     * APIT (Advance Personal Income Tax) on the achievement-scaled total package.
     * Uses Sri Lanka Inland Revenue summary tax brackets.
     */
    public static function apiit(float $monthlyPackage, array $context = []): float
    {
        $bracket = self::calculateBracketDetails($monthlyPackage);

        Log::info('[APIT Calculation] Tax evaluated', array_merge([
            'monthly_package'    => $monthlyPackage,
            'tier'               => $bracket['tier'],
            'rate'               => $bracket['rate'],
            'deduction_constant' => $bracket['deduction'],
            'calculation_method' => $bracket['description'],
            'formula'            => $bracket['formula'],
            'apit_tax'           => $bracket['tax'],
        ], $context));

        return $bracket['tax'];
    }
}
