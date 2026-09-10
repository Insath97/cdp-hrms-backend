<?php

namespace App\Services;

use App\Models\PayrollMonth;
use App\Models\User;
use Illuminate\Support\Carbon;

class PayrollActivationService
{
    /**
     * HR users with this permission can always see payroll details,
     * even for months that have not been activated yet.
     */
    public const MANAGE_PERMISSION = 'Payroll Activate';

    /**
     * Normalize a month value into "Y-m" format.
     * Accepts "2026-08", "August 2026", "Aug 2026", etc.
     */
    public static function normalizeMonth(string $month): ?string
    {
        $month = trim($month);
        if ($month === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $month;
        }

        try {
            return Carbon::parse($month)->format('Y-m');
        } catch (\Throwable $th) {
            return null;
        }
    }

    /**
     * Get or create the activation row for a given month.
     */
    public static function getOrCreate(string $month): ?PayrollMonth
    {
        $normalized = self::normalizeMonth($month);
        if (! $normalized) {
            return null;
        }

        return PayrollMonth::firstOrCreate(['month' => $normalized], ['status' => 'inactive']);
    }

    public static function status(string $month): string
    {
        $record = self::getOrCreate($month);
        return $record ? $record->status : 'inactive';
    }

    public static function isActive(string $month): bool
    {
        $record = self::getOrCreate($month);
        return $record && $record->isActive();
    }

    public static function isLocked(string $month): bool
    {
        $record = self::getOrCreate($month);
        return $record && $record->isLocked();
    }

    /**
     * Can this user view payroll details for the given month?
     * HR users with the manage permission bypass activation;
     * everyone else may only view activated (or locked) months.
     */
    public static function canView(string $month, ?User $user = null): bool
    {
        if ($user && $user->can(self::MANAGE_PERMISSION)) {
            return true;
        }

        return self::status($month) !== 'inactive';
    }
}
