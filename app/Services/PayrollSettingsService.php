<?php

namespace App\Services;

use App\Models\PayrollSetting;

class PayrollSettingsService
{
    public static function stampFeeAmount(): float
    {
        return (float) PayrollSetting::get('stamp_fee_amount', 25);
    }

    public static function stampFeeThreshold(): float
    {
        return (float) PayrollSetting::get('stamp_fee_threshold', 25000);
    }

    public static function whtRate(): float
    {
        return (float) PayrollSetting::get('wht_rate', 5);
    }

    public static function whtThreshold(): float
    {
        return (float) PayrollSetting::get('wht_threshold', 100000);
    }

    public static function mobilePaymentApplicable(): bool
    {
        return (bool) PayrollSetting::get('mobile_payment_applicable', false);
    }
}