<?php

namespace Tests\Unit;

use App\Models\Bank;
use App\Services\BankAccountFormatService;
use PHPUnit\Framework\TestCase;

class BankAccountFormatServiceTest extends TestCase
{
    private function bank(string $name, ?int $length): Bank
    {
        $bank = new Bank();
        $bank->name = $name;
        $bank->account_number_length = $length;

        return $bank;
    }

    public function test_it_pads_short_boc_accounts_with_leading_zeros()
    {
        $this->assertSame(
            '0012345678',
            BankAccountFormatService::padAccountNumber('12345678', $this->bank('Bank of Ceylon', 10))
        );
    }

    public function test_it_pads_short_hnb_accounts_with_leading_zeros()
    {
        $this->assertSame(
            '000123456789',
            BankAccountFormatService::padAccountNumber('123456789', $this->bank('Hatton National Bank', 12))
        );
    }

    public function test_it_leaves_correct_length_and_longer_numbers_alone()
    {
        $boc = $this->bank('Bank of Ceylon', 10);

        $this->assertSame('1234567890', BankAccountFormatService::padAccountNumber('1234567890', $boc));
        // Longer numbers must NOT be truncated — validation still rejects them.
        $this->assertSame('12345678901', BankAccountFormatService::padAccountNumber('12345678901', $boc));
    }

    public function test_it_only_pads_boc_and_hnb()
    {
        $other = $this->bank('Commercial Bank', 10);

        $this->assertSame('12345678', BankAccountFormatService::padAccountNumber('12345678', $other));
    }

    public function test_it_ignores_blank_non_numeric_and_unruled_cases()
    {
        $boc = $this->bank('Bank of Ceylon', 10);

        $this->assertSame('', BankAccountFormatService::padAccountNumber('', $boc));
        $this->assertSame('12AB5678', BankAccountFormatService::padAccountNumber('12AB5678', $boc));
        $this->assertSame('12345678', BankAccountFormatService::padAccountNumber('12345678', null));
        $this->assertSame(
            '12345678',
            BankAccountFormatService::padAccountNumber('12345678', $this->bank('Bank of Ceylon', null))
        );
    }
}
