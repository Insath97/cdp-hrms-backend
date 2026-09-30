<?php

namespace App\Services;

use App\Models\Bank;

/**
 * Validates employee bank account numbers against the per-bank CEFT format
 * rules and sanitises the free-text fields that go into the CUS salary file.
 */
class BankAccountFormatService
{
    /**
     * Validate an account number for the given bank.
     *
     * @return array{0: bool, 1: string} [isValid, errorMessage]
     */
    public static function validateAccountNumber(?string $accountNumber, ?Bank $bank): array
    {
        $accountNumber = trim((string) $accountNumber);

        if ($accountNumber === '') {
            return [true, '']; // Account numbers are optional on the employee record.
        }

        // Account numbers are numeric only, except Standard Chartered which
        // also accepts the leading "88" prefixed 12 digit form (still numeric).
        if (!preg_match('/^\d+$/', $accountNumber)) {
            return [false, 'Account number must contain digits only.'];
        }

        if ($bank === null || $bank->account_number_length === null) {
            return [true, ''];
        }

        $length = strlen($accountNumber);
        $expected = (int) $bank->account_number_length;

        // Standard Chartered accepts both 11 and 12 digit account numbers.
        if ($bank->name === 'Standard Chartered Bank' && in_array($length, [11, 12], true)) {
            return [true, ''];
        }

        // Bimputh Finance accepts 14 (old) or 15 (new) digit account numbers.
        if ($bank->name === 'Bimputh Finance PLC' && in_array($length, [14, 15], true)) {
            return [true, ''];
        }

        if ($length !== $expected) {
            return [false, "Account number for {$bank->name} must be {$expected} digits (got {$length})."];
        }

        return [true, ''];
    }

    /**
     * Strip characters the bank upload rejects: , - @ . ( ) and friends.
     * Used for beneficiary name / description / sender description fields.
     */
    public static function sanitizeAlphanumeric(?string $value, int $maxLength = 0): string
    {
        $value = (string) $value;
        // Remove the characters explicitly disallowed by the bank spec.
        $value = preg_replace('/[,.\-@()\[\]{}<>\/\\\\;:\'"&*#%_+=?!`~^$|]/u', ' ', $value) ?? '';
        // Collapse whitespace and keep only letters, digits and spaces.
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value);

        if ($maxLength > 0 && mb_strlen($value) > $maxLength) {
            $value = rtrim(mb_substr($value, 0, $maxLength));
        }

        return $value;
    }

    /**
     * Account numbers are numeric only, max 18 characters in the upload.
     */
    public static function sanitizeAccountNumber(?string $value): string
    {
        $value = preg_replace('/\D+/', '', (string) $value) ?? '';

        return mb_substr($value, 0, 18);
    }

    /**
     * Amount is numeric, maximum 18 characters, 2 decimals, no separators.
     */
    public static function formatAmount(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }
}
