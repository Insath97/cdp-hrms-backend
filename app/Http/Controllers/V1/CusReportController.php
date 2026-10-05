<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\Employee;
use App\Models\PayrollRecord;
use App\Models\PurposeCode;
use App\Services\BankAccountFormatService;
use App\Services\PayrollActivationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CUS (Corporate/Company Upload Sheet) salary upload report.
 *
 * Produces the fixed-width record layout required for the bank salary upload:
 * one row per employee for a single salary cycle (5th / 15th / 20th).
 */
class CusReportController extends Controller
{
    /**
     * Salary cycles. The pay_day enum is the 5th / 15th / 20th cycle.
     */
    public const PAY_DAYS = [
        'basic' => '5th',
        'commission' => '15th',
        'allowance' => '20th',
    ];

    private const DEFAULT_PURPOSE_CODE = '784001';

    /**
     * Header labels for the upload file, in the order the bank expects them.
     */
    private const HEADERS = [
        'TYPE',
        'TO_ACCOUNT',
        'AMOUNT',
        'SENDER DESCRIPTION',
        'BENEFICIARY DESCRIPTION',
        'BENEFICIARY NAME',
        'BENEFICIARY ID',
        'SWIFT CODE',
        'PURPOSE CODE',
    ];

    /**
     * JSON preview of the upload file.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => 'required|string',
            'pay_day' => 'required|string|in:basic,commission,allowance',
            'bank' => 'nullable|string|in:all,commercial,other',
            'purpose_code' => 'nullable|string|max:6',
        ]);

        $month = $this->normalizeMonth($validated['month']);
        if ($month === null) {
            return response()->json(['status' => 'error', 'message' => 'Invalid month format. Use YYYY-MM.'], 422);
        }

        if (!PayrollActivationService::canView($month, Auth::user())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payroll for this month has not been activated yet.',
            ], 403);
        }

        $payDay = $validated['pay_day'];
        $bankFilter = $validated['bank'] ?? 'all';
        $purpose = $this->resolvePurposeCode($validated['purpose_code'] ?? null);
        $purposeCode = $purpose['code'];
        $purposeDescription = $purpose['description'];
        $senderDescription = $this->senderDescription($month);

        $rows = $this->buildRows($month, $payDay, $bankFilter);

        $totalAmount = 0.0;
        foreach ($rows as $row) {
            $totalAmount += (float) $row['amount'];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'month' => $month,
                'pay_day' => $payDay,
                'pay_day_label' => self::PAY_DAYS[$payDay],
                'bank' => $bankFilter,
                'sender_description' => $senderDescription,
                'purpose_code' => $purposeCode,
                'purpose_description' => $purposeDescription,
                'count' => count($rows),
                'total_amount' => BankAccountFormatService::formatAmount($totalAmount),
                'skipped' => $this->skippedCount,
                'skipped_details' => $this->skippedDetails,
                'rows' => $rows,
            ],
        ]);
    }

    /**
     * Download the upload file.
     */
    public function download(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'month' => 'required|string',
            'pay_day' => 'required|string|in:basic,commission,allowance',
            'bank' => 'nullable|string|in:all,commercial,other',
            'purpose_code' => 'nullable|string|max:6',
            'format' => 'nullable|string|in:excel,xlsx,xls,csv',
        ]);

        $month = $this->normalizeMonth($validated['month']);
        if ($month === null) {
            return response()->json(['status' => 'error', 'message' => 'Invalid month format. Use YYYY-MM.'], 422);
        }

        if (!PayrollActivationService::canView($month, Auth::user())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payroll for this month has not been activated yet.',
            ], 403);
        }

        $payDay = $validated['pay_day'];
        $bankFilter = $validated['bank'] ?? 'all';
        $purpose = $this->resolvePurposeCode($validated['purpose_code'] ?? null);
        $senderDescription = $this->senderDescription($month);
        $format = strtolower($validated['format'] ?? 'excel');

        $rows = $this->buildRows($month, $payDay, $bankFilter);

        $bankLabel = match ($bankFilter) {
            'commercial' => 'COMMERCIAL',
            'other' => 'OTHER',
            default => 'ALL',
        };

        if (in_array($format, ['excel', 'xlsx', 'xls'], true)) {
            $filename = sprintf(
                'CUS_%s_%s_%s.xls',
                $payDay,
                $bankLabel,
                $month
            );

            return $this->downloadExcelXml($filename, $rows, $senderDescription, $purpose);
        }

        $filename = sprintf(
            'CUS_%s_%s_%s.csv',
            $payDay,
            $bankLabel,
            $month
        );

        $callback = function () use ($rows, $senderDescription, $purpose) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, self::HEADERS);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['type'],
                    $r['to_account'],
                    $r['amount'],
                    $senderDescription,
                    $r['beneficiary_description'],
                    $r['beneficiary_name'],
                    $r['beneficiary_id'],
                    $r['swift_code'],
                    $purpose['code'],
                ]);
            }
            fclose($out);
        };

        return new StreamedResponse($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Generate Excel XML spreadsheet format (.xls) opened natively by Microsoft Excel.
     * Preserves account numbers as text so leading zeros aren't dropped and numbers don't convert to scientific notation.
     */
    private function downloadExcelXml(string $filename, array $rows, string $senderDescription, array $purpose): StreamedResponse
    {
        $callback = function () use ($rows, $senderDescription, $purpose) {
            $escape = fn ($val) => htmlspecialchars((string) $val, ENT_XML1, 'UTF-8');

            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\r\n";
            echo '<?mso-application progid="Excel.Sheet"?>' . "\r\n";
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\r\n";
            echo ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\r\n";
            echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\r\n";
            echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\r\n";
            echo ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\r\n";
            echo ' <Styles>' . "\r\n";
            echo '  <Style ss:ID="Header">' . "\r\n";
            echo '   <Font ss:Bold="1" ss:Color="#FFFFFF"/>' . "\r\n";
            echo '   <Interior ss:Color="#1E3A8A" ss:Pattern="Solid"/>' . "\r\n";
            echo '   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . "\r\n";
            echo '  </Style>' . "\r\n";
            echo '  <Style ss:ID="Text">' . "\r\n";
            echo '   <NumberFormat ss:Format="@"/>' . "\r\n";
            echo '  </Style>' . "\r\n";
            echo '  <Style ss:ID="Currency">' . "\r\n";
            echo '   <NumberFormat ss:Format="0.00"/>' . "\r\n";
            echo '   <Alignment ss:Horizontal="Right"/>' . "\r\n";
            echo '  </Style>' . "\r\n";
            echo '  <Style ss:ID="SummaryLabel">' . "\r\n";
            echo '   <Font ss:Bold="1" ss:Color="#1E3A8A"/>' . "\r\n";
            echo '   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>' . "\r\n";
            echo '  </Style>' . "\r\n";
            echo '  <Style ss:ID="SummaryValue">' . "\r\n";
            echo '   <Font ss:Bold="1"/>' . "\r\n";
            echo '   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>' . "\r\n";
            echo '  </Style>' . "\r\n";
            echo '  <Style ss:ID="SummaryCurrency">' . "\r\n";
            echo '   <Font ss:Bold="1"/>' . "\r\n";
            echo '   <NumberFormat ss:Format="#,##0.00"/>' . "\r\n";
            echo '   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>' . "\r\n";
            echo '  </Style>' . "\r\n";
            echo ' </Styles>' . "\r\n";
            echo ' <Worksheet ss:Name="CUS Report">' . "\r\n";
            echo '  <Table>' . "\r\n";

            // Calculate totals
            $totalTransactions = count($rows);
            $bulkFileAmount = 0.0;
            foreach ($rows as $r) {
                $bulkFileAmount += (float) ($r['amount'] ?? 0);
            }

            // Top Summary Row 1: No of Transactions
            echo '   <Row>' . "\r\n";
            echo '    <Cell ss:StyleID="SummaryLabel"><Data ss:Type="String">No of Transactions</Data></Cell>' . "\r\n";
            echo '    <Cell ss:StyleID="SummaryValue"><Data ss:Type="Number">' . $totalTransactions . '</Data></Cell>' . "\r\n";
            echo '   </Row>' . "\r\n";

            // Top Summary Row 2: Bulk File Amount
            echo '   <Row>' . "\r\n";
            echo '    <Cell ss:StyleID="SummaryLabel"><Data ss:Type="String">Bulk File Amount</Data></Cell>' . "\r\n";
            echo '    <Cell ss:StyleID="SummaryCurrency"><Data ss:Type="Number">' . number_format($bulkFileAmount, 2, '.', '') . '</Data></Cell>' . "\r\n";
            echo '   </Row>' . "\r\n";

            // Top Summary Row 3: Blank separator row
            echo '   <Row></Row>' . "\r\n";

            // Table Column Headers (Row 4)
            echo '   <Row ss:StyleID="Header">' . "\r\n";
            foreach (self::HEADERS as $header) {
                echo '    <Cell><Data ss:Type="String">' . $escape($header) . '</Data></Cell>' . "\r\n";
            }
            echo '   </Row>' . "\r\n";

            // Data rows
            foreach ($rows as $r) {
                echo '   <Row>' . "\r\n";
                // Type (numeric: 1 = commercial, 2 = other bank)
                echo '    <Cell><Data ss:Type="Number">' . ((int) $r['type']) . '</Data></Cell>' . "\r\n";
                // To account (treated strictly as string to preserve leading zeroes)
                echo '    <Cell ss:StyleID="Text"><Data ss:Type="String">' . $escape($r['to_account']) . '</Data></Cell>' . "\r\n";
                // Amount
                echo '    <Cell ss:StyleID="Currency"><Data ss:Type="Number">' . $escape($r['amount']) . '</Data></Cell>' . "\r\n";
                // Sender Description
                echo '    <Cell><Data ss:Type="String">' . $escape($senderDescription) . '</Data></Cell>' . "\r\n";
                // Beneficiary Description
                echo '    <Cell><Data ss:Type="String">' . $escape($r['beneficiary_description']) . '</Data></Cell>' . "\r\n";
                // Beneficiary Name
                echo '    <Cell><Data ss:Type="String">' . $escape($r['beneficiary_name']) . '</Data></Cell>' . "\r\n";
                // Beneficiary ID
                echo '    <Cell ss:StyleID="Text"><Data ss:Type="String">' . $escape($r['beneficiary_id']) . '</Data></Cell>' . "\r\n";
                // SWIFT Code
                echo '    <Cell ss:StyleID="Text"><Data ss:Type="String">' . $escape($r['swift_code']) . '</Data></Cell>' . "\r\n";
                // Purpose Code
                echo '    <Cell ss:StyleID="Text"><Data ss:Type="String">' . $escape($purpose['code']) . '</Data></Cell>' . "\r\n";
                echo '   </Row>' . "\r\n";
            }

            echo '  </Table>' . "\r\n";
            echo ' </Worksheet>' . "\r\n";
            echo '</Workbook>' . "\r\n";
        };

        return new StreamedResponse($callback, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private int $skippedCount = 0;

    /** @var array<int, array<string, string>> */
    private array $skippedDetails = [];

    /**
     * Build the upload rows for one month + cycle + bank filter.
     *
     * Only PROCESSED payroll records are included, and the amount is the
     * net (take-home) figure for that cycle.
     */
    private function buildRows(string $month, string $payDay, string $bankFilter): array
    {
        $this->skippedCount = 0;
        $this->skippedDetails = [];

        $query = PayrollRecord::query()
            ->with(['employee.bank', 'user.employee.bank'])
            ->where('month', $month)
            ->where('pay_day', $payDay)
            ->where('status', 'processed');

        $records = $query->get()->filter(function ($record) {
            return $this->employeeFor($record) !== null;
        })->sortBy(function ($record) {
            return $this->employeeSortKey($this->employeeFor($record));
        })->values();

        $rows = [];
        foreach ($records as $record) {
            /** @var Employee $employee */
            $employee = $this->employeeFor($record);
            $bank = $employee->bank ?? ($employee->bank_name ? Bank::where('name', $employee->bank_name)->first() : null);

            // The bank filter indicates the upload sheet format/target bank system,
            // not a filter to exclude employees who hold accounts at other banks.

            $accountNumber = BankAccountFormatService::sanitizeAccountNumber($employee->account_number);
            $name = BankAccountFormatService::sanitizeAlphanumeric($employee->full_name, 25);

            // Re-use the per-bank format rules so a bad account number is
            // reported as skipped instead of being rejected by the bank.
            [$accountIsValid, $accountError] = BankAccountFormatService::validateAccountNumber($accountNumber, $bank);

            if ($accountNumber === '' || $name === '' || !$accountIsValid) {
                $this->skippedCount++;
                $this->skippedDetails[] = [
                    'employee_code' => (string) ($employee->employee_code ?? ''),
                    'reason' => $accountNumber === ''
                        ? 'Missing bank account number'
                        : ($name === '' ? 'Missing employee name' : $accountError),
                ];
                continue;
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'employee_code' => (string) ($employee->employee_code ?? ''),
                'employee_name' => (string) ($employee->full_name ?? ''),
                'bank_name' => $bank?->name ?? (string) ($employee->bank_name ?? ''),
                'type' => $bank && $bank->is_commercial ? '1' : '2',
                'to_account' => $accountNumber,
                'amount' => BankAccountFormatService::formatAmount((float) $record->net),
                'beneficiary_description' => BankAccountFormatService::sanitizeAlphanumeric($employee->employee_code, 30),
                'beneficiary_name' => $name,
                'beneficiary_id' => BankAccountFormatService::sanitizeAlphanumeric($employee->id_number, 30),
                'swift_code' => (string) ($bank?->swift_code ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * "Company Salaries [August 2026]" capped at 30 characters.
     */
    private function senderDescription(string $month): string
    {
        $period = Carbon::createFromFormat('Y-m', $month);
        $label = sprintf('Company Salaries [%s]', $period->format('F Y'));

        return BankAccountFormatService::sanitizeAlphanumeric($label, 30);
    }

    /**
     * @return array{code: string, description: string}
     */
    private function resolvePurposeCode(?string $code): array
    {
        $purpose = $code
            ? PurposeCode::where('code', $code)->first()
            : PurposeCode::where('is_default', true)->first();

        if ($purpose === null && $code !== null) {
            $purpose = PurposeCode::where('code', $code)->first();
        }
        if ($purpose === null) {
            $purpose = PurposeCode::where('code', self::DEFAULT_PURPOSE_CODE)->first();
        }

        return [
            'code' => $purpose?->code ?? self::DEFAULT_PURPOSE_CODE,
            'description' => $purpose?->description ?? 'Consultancy Fees, Legal Charges and Salaries',
        ];
    }

    /**
     * Resolve the employee for a payroll record.
     *
     * The record's own employee_id is authoritative; the user -> employee
     * relation is only a fallback for legacy rows created before employee_id
     * was populated on payroll_records.
     */
    private function employeeFor(PayrollRecord $record): ?Employee
    {
        return $record->employee ?? $record->user?->employee;
    }

    private function employeeSortKey(Employee $employee): array
    {
        $code = trim((string) ($employee->employee_code ?? ''));

        // 1. Purely numeric employee codes come first (0), alphanumeric come second (1)
        $isPurelyNumeric = preg_match('/^[0-9]+$/', $code) === 1 ? 0 : 1;

        // 2. Extracted number as unsigned integer
        $number = preg_match('/[0-9]+/', $code, $m) ? (int) $m[0] : PHP_INT_MAX;

        // 3. Original string as tie-breaker
        return [$isPurelyNumeric, $number, $code];
    }

    private function normalizeMonth(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^\d{6}$/', $raw)) {
            return substr($raw, 0, 4) . '-' . substr($raw, 4, 2);
        }
        try {
            return Carbon::parse($raw)->format('Y-m');
        } catch (\Exception $e) {
            return null;
        }
    }
}
