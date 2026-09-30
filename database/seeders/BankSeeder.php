<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    /**
     * SWIFT codes per the bank list supplied for the CUS salary upload.
     * Commercial Bank has no SWIFT code (the field is left blank for it).
     *
     * account_number_length / format describe the CEFT account number rule
     * for that bank. Where a bank accepts more than one length the smallest
     * common length is stored and the exception is noted in the format text.
     */
    public function run(): void
    {
        $banks = [
            ['Amana Bank', 'AMNALKLX001', 13, '13 digits for any account number', null],
            ['Axis Bank', null, 12, '12 digits for any account number', null],
            ['Bank of Ceylon', 'BCEYLKLX001', 10, '10 digit for any account number, without the branch code', null],
            ['Bimputh Finance PLC', null, 14, '15 digit for New account number, 14 digit for old Account number', null],
            ['Cargills Bank', 'CGRBLKLX001', 12, '12 digits for any account number', null],
            ['CDB', null, 18, '18 digits for any account number', null],
            ['Central Finance', 'CEFILKLX001', 12, '12 digits for any account number', null],
            ['Citibank', 'CITILKLX001', 10, '10 digits for any account number', null],
            ['CLC', null, 11, '11 digits for any account number, Ex: 00xxxxxxxxx / 05xxxxxxxxx', '00xxxxxxxxx'],
            ['Commercial Bank', null, 10, '10 digits for any account number', null, true],
            ['Deutsche Bank', 'DEUTLKLX001', 10, '10 digits for any account number, Ex: 00xxxxxxxx', '00xxxxxxxx'],
            ['DFCC Bank', 'DFCCLKLX001', 12, '12 digits for any account number', null],
            ['Dialog Finance PLC', 'DIALLKLX001', 12, '12 digits for any account number', null],
            ['Habib Bank', 'HABBLKLC001', 13, '13 digits for any account number', null],
            ['Hatton National Bank', 'HBLILKLX001', 12, '12 digits for any account number, Ex: 001010011327', '001010011327'],
            ['HDFC Bank', 'HDFCLKLX001', 12, '12 digits for any account number', null],
            ['HSBC', 'HSBCLKLX001', 12, '12 digit for any account number, without the hyphen', null],
            ['Indian Bank', 'IDIBLKLC001', 12, '12 digits for any account number', null],
            ['Indian Overseas Bank', 'IOBALKLC001', 12, '12 digits for any account number', null],
            ['LB Finance', 'LBFILKLX001', 15, '15 digits for any account number', null],
            ['LOFC', null, 11, '11 digits for any account number, Ex: 00xxxxxxxxx / 05xxxxxxxxx', '00xxxxxxxxx'],
            ['LOLC Development Finance PLC', 'LANKLKLX001', 11, '11 digit for Any account number', null],
            ['MCB Bank', null, 12, '12 digits for any account number', null],
            ['Nations Trust Bank', 'NTBCLKLX001', 12, '12 digits for any account number', null],
            ['NDB Bank', 'NDBSLKLX001', 12, '12 digits for any account number', null],
            ['HNB Finance Limited', 'HNBGLKLX001', 12, '12 digits for any account number', null],
            ['NSB', 'NSBALKLX001', 12, '12 digits for any account number', null],
            ['PABC', 'PABSLKLX001', 12, '12 digits for any account number, Ex: 2XXXXXXXXXXX (Saving Account) / 1XXXXXXXXXXX (Current Account)', '2XXXXXXXXXXX'],
            ["People's Bank", 'PSBKLKLX001', 15, '15 digits for any account number, Ex: 204200100091326', '204200100091326'],
            ['Public Bank', 'PBBELKLX001', 13, '13 digits for any account number', null],
            ['Public Bank Berhad', 'PBBELKLX001', 13, '13 digits for any account number', null],
            ['Regional Development Bank', 'RDBBLKLX001', 12, '12 digits for any account number', null],
            ['Sampath Bank', 'BSAMLKLX001', 12, '12 digits for any account number, Ex: 10xxxxxxxxxx', '10xxxxxxxxxx'],
            ['State Bank of India', 'SBINLKLX001', 14, '14 digits for any account number', null],
            ['SDB Bank', null, 10, '10 digits for any account number', null],
            ['Senkadagala Finance', 'SENKLKLX001', 12, '12 digits for any account number', null],
            ['Seylan Bank', 'SEYBLKLX001', 15, '15 digits for any account number', null],
            ['Standard Chartered Bank', 'SCBLLKLX001', 11, '11 digit account number - Ex: 18xxxxxxxxx / 01xxxxxxxxx , 12 digit account number - Ex: 88xxxxxxxxxx', '18xxxxxxxxx'],
            ['Union Bank', 'UBCLLKLC001', 16, '16 digits for any account number', null],
        ];

        // Finance companies that appear on the SWIFT list but not the account-format list.
        $swiftOnly = [
            ['Bank of China LTD Colombo', 'BANKLKLX001'],
            ['Commercial Leasing & Finance', 'COMMLKLX001'],
            ['CDB Finance', 'CDBFLKLX001'],
            ['Co-operative Rural Bank', 'PCRBLKLX001'],
            ['HNB Grameen Finance', 'HNBGLKLX001'],
            ['MBSL & Finance Plc', 'MBSLLKLX001'],
            ['Mercantile Investment', 'MERCLKLX001'],
            ['National Savings Bank', 'NSBALKLX001'],
            ['Pan Asia Bank', 'PABSLKLX001'],
            ["People's Leasing", 'PEOPLKLX001'],
            ['Sanasa Development Bank', 'SANALKLX001'],
            ['Senkadagala Finance Head Office', 'SENKLKLX001'],
            ['Siyapatha Finance', 'SIYALKLX001'],
            ['Softlogic Finance Head Office', 'SOFTLKLX001'],
            ['Singer Finance', 'SINGLKLX001'],
            ['SMIB', 'SMIBLKLX001'],
            ['Union Bank(Pakistan)Ltd', 'UNIOLKLX001'],
            ['Vallibel Finance', 'VALLLKLX001'],
        ];

        foreach ($banks as $bank) {
            $isCommercial = (bool) ($bank[5] ?? false);
            Bank::updateOrCreate(
                ['name' => $bank[0]],
                [
                    'swift_code' => $bank[1],
                    'account_number_length' => $bank[2],
                    'account_number_format' => $bank[3],
                    'account_number_example' => $bank[4] ?? null,
                    'is_commercial' => $isCommercial,
                    'is_active' => true,
                ]
            );
        }

        foreach ($swiftOnly as [$name, $swift]) {
            if (Bank::where('name', $name)->exists()) {
                Bank::where('name', $name)->update(['swift_code' => $swift]);
                continue;
            }
            Bank::updateOrCreate(
                ['name' => $name],
                ['swift_code' => $swift, 'is_active' => true]
            );
        }
    }
}
