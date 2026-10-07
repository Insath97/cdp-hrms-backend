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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;


/**
 * CUS (Corporate/Company Upload Sheet) salary upload report.
 *
 * Produces the bank salary upload using the original CUS Excel template.
 *
 * The template is used as the source of truth for:
 * - formatting
 * - borders
 * - colours
 * - fonts
 * - formulas
 * - validation/helper columns
 * - column widths
 * - worksheet structure
 */
class CusReportController extends Controller
{
    /**
     * Salary cycles.
     */
    public const PAY_DAYS = [
        'basic' => '5th',
        'commission' => '15th',
        'allowance' => '20th',
    ];

    private const DEFAULT_PURPOSE_CODE = '784001';

    /**
     * CUS Excel template.
     *
     * Store the original template at:
     *
     * storage/app/templates/Salary_Cus_File_Format.xlsm
     */
    private const TEMPLATE_PATH =
        'templates/Salary_Cus_File_Format.xlsm';

    /**
     * Header labels for the upload file.
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
     * Data starts from row 6 in the original CUS template.
     */
    private const DATA_START_ROW = 6;

    /**
     * Maximum data row in the original template.
     *
     * The supplied template has rows up to 5005.
     */
    private const DATA_END_ROW = 5005;

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
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid month format. Use YYYY-MM.',
            ], 422);
        }

        if (!PayrollActivationService::canView($month, Auth::user())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payroll for this month has not been activated yet.',
            ], 403);
        }

        $payDay = $validated['pay_day'];
        $bankFilter = $validated['bank'] ?? 'all';

        $purpose = $this->resolvePurposeCode(
            $validated['purpose_code'] ?? null
        );

        $purposeCode = $purpose['code'];
        $purposeDescription = $purpose['description'];

        $senderDescription = $this->senderDescription($month);

        $rows = $this->buildRows(
            $month,
            $payDay,
            $bankFilter
        );

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
                'total_amount' =>
                    BankAccountFormatService::formatAmount($totalAmount),
                'skipped' => $this->skippedCount,
                'skipped_details' => $this->skippedDetails,
                'rows' => $rows,
            ],
        ]);
    }

    /**
     * Download the CUS upload file.
     *
     * Excel/XLSM:
     * Uses the original CUS template.
     *
     * CSV:
     * Creates a normal CSV file.
     */
    public function download(
        Request $request
    ): StreamedResponse|BinaryFileResponse|JsonResponse {
        $validated = $request->validate([
            'month' => 'required|string',
            'pay_day' => 'required|string|in:basic,commission,allowance',
            'bank' => 'nullable|string|in:all,commercial,other',
            'purpose_code' => 'nullable|string|max:6',
            'format' => 'nullable|string|in:excel,xlsx,xls,csv',
        ]);

        $month = $this->normalizeMonth($validated['month']);

        if ($month === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid month format. Use YYYY-MM.',
            ], 422);
        }

        if (!PayrollActivationService::canView($month, Auth::user())) {
            return response()->json([
                'status' => 'error',
                'message' =>
                    'Payroll for this month has not been activated yet.',
            ], 403);
        }

        $payDay = $validated['pay_day'];
        $bankFilter = $validated['bank'] ?? 'all';

        $purpose = $this->resolvePurposeCode(
            $validated['purpose_code'] ?? null
        );

        $senderDescription = $this->senderDescription($month);

        $format = strtolower(
            $validated['format'] ?? 'excel'
        );

        $rows = $this->buildRows(
            $month,
            $payDay,
            $bankFilter
        );

        $bankLabel = match ($bankFilter) {
            'commercial' => 'COMMERCIAL',
            'other' => 'OTHER',
            default => 'ALL',
        };

        /*
         * Use the original XLSM template for Excel downloads.
         */
        if (in_array($format, ['excel', 'xlsx', 'xls'], true)) {
            $filename = sprintf(
                'CUS_%s_%s_%s.xlsx',
                $payDay,
                $bankLabel,
                $month
            );

            return $this->downloadExcelTemplate(
                $filename,
                $rows,
                $senderDescription,
                $purpose
            );
        }

        /*
         * CSV download.
         */
        $filename = sprintf(
            'CUS_%s_%s_%s.csv',
            $payDay,
            $bankLabel,
            $month
        );

        $callback = function () use (
            $rows,
            $senderDescription,
            $purpose
        ) {
            $out = fopen('php://output', 'w');

            /*
             * UTF-8 BOM.
             */
            fprintf(
                $out,
                chr(0xEF) . chr(0xBB) . chr(0xBF)
            );

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

        return new StreamedResponse(
            $callback,
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' =>
                    "attachment; filename=\"{$filename}\"",
                'Cache-Control' =>
                    'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
            ]
        );
    }

    /**
     * Generate the Excel report using the original CUS template.
     *
     * IMPORTANT:
     * We do NOT create a new spreadsheet.
     *
     * We load the original template and only replace the
     * report data in C:K.
     *
     * This allows the original template to retain its:
     * - colours
     * - borders
     * - fonts
     * - formulas
     * - conditional formatting
     * - helper columns
     * - validation structure
     * - worksheet structure
     * - column widths
     * - row heights
     */
    private function downloadExcelTemplate(
        string $filename,
        array $rows,
        string $senderDescription,
        array $purpose
    ): BinaryFileResponse {
        $templatePath = storage_path(
            'app/' . self::TEMPLATE_PATH
        );

        if (!file_exists($templatePath)) {
            abort(
                500,
                'CUS Excel template not found at: ' . $templatePath
            );
        }

        /*
         * Load the original XLSM template.
         *
         * PhpSpreadsheet cannot round-trip ActiveX controls or the
         * full VBA project — it silently drops them, causing Excel
         * to show a repeated "format/extension don't match" warning.
         *
         * Since the VBA macros and ActiveX controls are NOT required
         * for the bank upload (only the data and cell formatting
         * matter), we strip the macros after loading and output a
         * clean .xlsx file instead.
         */
        $spreadsheet = IOFactory::load($templatePath);

        /*
         * Strip VBA/macros so the output is a valid .xlsx.
         *
         * This removes:
         *  - the VBA project binary (vbaProject.bin)
         *  - the digital certificate (vbaProjectSignature.bin)
         *  - any ActiveX control metadata
         *
         * Without this, PhpSpreadsheet produces a .xlsm with missing
         * ActiveX parts, which Excel flags on every open.
         */
        $spreadsheet->discardMacros();

        /*
         * Get the DATA worksheet from the template.
         */
        $sheet = $spreadsheet->getSheetByName('DATA');

        if ($sheet === null) {
            abort(
                500,
                'DATA worksheet was not found in the CUS template.'
            );
        }

        /*
         * Hide the helper columns that are not part of the bank upload.
         *
         * A, B, L, M and N contain the template's own summary formulas,
         * per-row validation helpers and VLOOKUPs. They must NOT be
         * deleted (the summary block and row formulas reference them),
         * so they are hidden instead. Hidden columns keep every formula
         * working and keep the file structurally valid.
         */
        foreach (['A', 'B', 'L', 'M', 'N'] as $helperColumn) {
            $sheet->getColumnDimension($helperColumn)->setVisible(false);
        }

        /*
         * Strip characters that are illegal in XML before writing text.
         *
         * A single control character (e.g. pasted from bank/employee data)
         * makes the whole workbook unreadable, and Excel responds with a
         * repair dialog on every open.
         */
        $cleanText = static fn ($value): string => (string) preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',
            '',
            (string) $value
        );

        /*
         * Clear ONLY the data columns C:K.
         *
         * We deliberately do NOT remove rows.
         *
         * This is important because the template already contains
         * formulas, formatting and validation/helper formulas
         * down to row 5005.
         */
        for (
            $row = self::DATA_START_ROW;
            $row <= self::DATA_END_ROW;
            $row++
        ) {
            for ($column = 3; $column <= 11; $column++) {
                /*
                 * C = 3
                 * D = 4
                 * E = 5
                 * F = 6
                 * G = 7
                 * H = 8
                 * I = 9
                 * J = 10
                 * K = 11
                 */
                $sheet->setCellValue(
                    Coordinate::stringFromColumnIndex($column) . $row,
                    null
                );
            }
        }

        /*
         * Write the new payroll rows.
         */
        $currentRow = self::DATA_START_ROW;

        foreach ($rows as $r) {
            /*
             * ---------------------------------------------------------
             * C = TYPE
             * ---------------------------------------------------------
             *
             * VERY IMPORTANT:
             *
             * This must be a NUMBER.
             *
             * The template has formulas such as:
             *
             *     $C6=1
             *
             * Therefore we must NOT save this as text "1".
             */
            $sheet->setCellValue(
                "C{$currentRow}",
                (int) $r['type']
            );

            /*
             * ---------------------------------------------------------
             * D = TO ACCOUNT
             * ---------------------------------------------------------
             *
             * Keep bank account numbers as TEXT.
             *
             * This protects leading zeroes and prevents Excel
             * from converting long account numbers to scientific
             * notation.
             */
            $sheet->setCellValueExplicit(
                "D{$currentRow}",
                $cleanText($r['to_account']),
                DataType::TYPE_STRING
            );

            /*
             * ---------------------------------------------------------
             * E = AMOUNT
             * ---------------------------------------------------------
             *
             * Amount is a real Excel number.
             */
            $sheet->setCellValue(
                "E{$currentRow}",
                (float) $r['amount']
            );

            /*
             * ---------------------------------------------------------
             * F = SENDER DESCRIPTION
             * ---------------------------------------------------------
             */
            $sheet->setCellValue(
                "F{$currentRow}",
                $cleanText($senderDescription)
            );

            /*
             * ---------------------------------------------------------
             * G = BENEFICIARY DESCRIPTION
             * ---------------------------------------------------------
             */
            $sheet->setCellValue(
                "G{$currentRow}",
                $cleanText($r['beneficiary_description'])
            );

            /*
             * ---------------------------------------------------------
             * H = BENEFICIARY NAME
             * ---------------------------------------------------------
             */
            $sheet->setCellValue(
                "H{$currentRow}",
                $cleanText($r['beneficiary_name'])
            );

            /*
             * ---------------------------------------------------------
             * I = BENEFICIARY ID
             * ---------------------------------------------------------
             *
             * Keep as text.
             */
            $sheet->setCellValueExplicit(
                "I{$currentRow}",
                $cleanText($r['beneficiary_id']),
                DataType::TYPE_STRING
            );

            /*
             * ---------------------------------------------------------
             * J = SWIFT CODE
             * ---------------------------------------------------------
             *
             * Keep as text.
             */
            $sheet->setCellValueExplicit(
                "J{$currentRow}",
                $cleanText($r['swift_code']),
                DataType::TYPE_STRING
            );

            /*
             * ---------------------------------------------------------
             * K = PURPOSE CODE
             * ---------------------------------------------------------
             *
             * Keep as text.
             *
             * Example:
             *
             * 784001
             *
             * This is a code, not a mathematical number.
             */
            $sheet->setCellValueExplicit(
                "K{$currentRow}",
                $cleanText($purpose['code']),
                DataType::TYPE_STRING
            );

            $currentRow++;
        }

        /*
         * Update the template's summary formulas/values.
         *
         * The original template already calculates:
         *
         * B4  = SUM(B6:B5005)
         * N4  = SUM(N6:N5005)
         * K2  = SUM(E6:E5005)
         *
         * Therefore we normally do NOT need to manually set these.
         *
         * Excel will recalculate them when the user opens the file.
         */

        /*
         * Force Excel to recalculate formulas when opening the file.
         *
         * In PhpSpreadsheet v2+, getCalculationProperties() and
         * Calculation\Properties were removed. Recalculation on open
         * is ensured by setPreCalculateFormulas(false) on the writer
         * below, which instructs Excel to recalculate when it opens.
         */

        /*
         * Create a temporary XLSX file.
         *
         * We use .xlsx because macros have been stripped above.
         */
        $temporaryPath = tempnam(
            sys_get_temp_dir(),
            'cus_'
        ) . '.xlsx';

        /*
         * Save as a clean XLSX file.
         *
         * Macros have been stripped above, so this produces a
         * standards-compliant .xlsx with no Excel warnings.
         */
        $writer = IOFactory::createWriter(
            $spreadsheet,
            'Xlsx'
        );

        /*
         * Do not ask PhpSpreadsheet to pre-calculate formulas.
         * Excel will recalculate them when the workbook opens.
         */
        $writer->setPreCalculateFormulas(false);

        /*
         * Instruct Excel to force a full recalculation on open.
         * This ensures SUM/VLOOKUP formula cells refresh immediately.
         */
        if (method_exists($writer, 'setForceFullCalc')) {
            $writer->setForceFullCalc(true);
        }

        $writer->save($temporaryPath);

        /*
         * Remove the orphaned macro-button drawings.
         *
         * The bank's .xlsm template embeds macro buttons (Save / Create /
         * Reset / Exit / Clear) as legacy VML drawings. We strip the VBA
         * project above, so those buttons point at nothing — and Excel
         * responds by removing the whole VML part on EVERY open (the
         * "repaired" recovery log naming vmlDrawing1.vml/vmlDrawing6.vml).
         *
         * The buttons are useless without their macros (the bank upload
         * only needs the C:K data), so the parts and every reference to
         * them are deleted. Anything else — e.g. instruction images — is
         * left untouched.
         */
        $this->stripOrphanedMacroDrawings($temporaryPath);

        /*
         * Return the completed XLSX file.
         */
        return response()
            ->download(
                $temporaryPath,
                $filename,
                [
                    'Content-Type' =>
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Cache-Control' =>
                        'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                ]
            )
            ->deleteFileAfterSend(true);
    }

    private int $skippedCount = 0;

    /**
     * Delete legacy VML drawing parts left orphaned by macro stripping,
     * together with every reference to them.
     *
     * Only the exact parts Excel flags are removed; the method silently
     * skips anything it cannot find so it can never break a valid file.
     */
    private function stripOrphanedMacroDrawings(string $xlsxPath): void
    {
        $targets = ['vmlDrawing1.vml', 'vmlDrawing6.vml'];
        $relsNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        $src = new \ZipArchive();
        if ($src->open($xlsxPath) !== true) {
            return;
        }

        // Read-only pass: decide every edit up front. The archive is only
        // ever modified through a fresh copy below, so in-place update
        // quirks cannot silently drop changes.
        $replacements = [];
        $deletions = [];

        $entries = [];
        for ($i = 0; $i < $src->numFiles; $i++) {
            $entries[] = $src->getNameIndex($i);
        }

        foreach ($targets as $vml) {
            $partPath = 'xl/drawings/' . $vml;

            foreach ($entries as $relsName) {
                if (!preg_match('#^xl/worksheets/_rels/(sheet\d+)\.xml\.rels$#', $relsName, $m)) {
                    continue;
                }

                $relsXml = $src->getFromName($relsName);
                $rels = new \DOMDocument();
                if ($relsXml === false || $rels->loadXML($relsXml) === false) {
                    continue;
                }

                // Collect first: the writer can emit the same target twice,
                // and this NodeList is live (removing while iterating skips
                // nodes). Every match must go, or Excel keeps repairing.
                $refIds = [];
                $stale = [];
                foreach ($rels->getElementsByTagName('Relationship') as $rel) {
                    if (str_ends_with($rel->getAttribute('Target'), 'drawings/' . $vml)) {
                        $refIds[] = $rel->getAttribute('Id');
                        $stale[] = $rel;
                    }
                }
                foreach ($stale as $rel) {
                    $rel->parentNode->removeChild($rel);
                }
                if ($stale !== []) {
                    $replacements[$relsName] = $rels->saveXML();
                }

                if ($refIds === []) {
                    continue;
                }

                $sheetName = 'xl/worksheets/' . $m[1] . '.xml';
                $sheetXml = $src->getFromName($sheetName);
                $sheet = new \DOMDocument();
                if ($sheetXml !== false && $sheet->loadXML($sheetXml)) {
                    $removed = false;
                    foreach ($sheet->getElementsByTagName('legacyDrawing') as $node) {
                        if (in_array($node->getAttributeNS($relsNs, 'id'), $refIds, true)) {
                            $node->parentNode->removeChild($node);
                            $removed = true;
                        }
                    }
                    if ($removed) {
                        $replacements[$sheetName] = $sheet->saveXML();
                    }
                }
            }

            $deletions[] = $partPath;
            $deletions[] = 'xl/drawings/_rels/' . $vml . '.rels';

            // Drop an Override content-type entry for the part, if any.
            // Shared Default entries (e.g. Extension="vml") are left alone.
            $ctXml = $src->getFromName('[Content_Types].xml');
            $ct = new \DOMDocument();
            if ($ctXml !== false && $ct->loadXML($ctXml)) {
                $changed = false;
                foreach ($ct->getElementsByTagName('Override') as $override) {
                    if ($override->getAttribute('PartName') === '/' . $partPath) {
                        $override->parentNode->removeChild($override);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $replacements['[Content_Types].xml'] = $ct->saveXML();
                }
            }
        }

        // Write pass: stream every entry into a brand-new archive.
        $tmpPath = tempnam(sys_get_temp_dir(), 'cus_clean_');
        if ($tmpPath === false) {
            $src->close();
            return;
        }

        $dst = new \ZipArchive();
        if ($dst->open($tmpPath, \ZipArchive::OVERWRITE) !== true) {
            $src->close();
            return;
        }

        foreach ($entries as $name) {
            if (in_array($name, $deletions, true)) {
                continue;
            }
            $content = array_key_exists($name, $replacements)
                ? $replacements[$name]
                : $src->getFromName($name);
            if ($content !== false) {
                $dst->addFromString($name, $content);
            }
        }

        $dst->close();
        $src->close();

        // Atomically swap the cleaned file into place.
        copy($tmpPath, $xlsxPath);
        @unlink($tmpPath);
    }

    /** @var array<int, array<string, string>> */
    private array $skippedDetails = [];

    /**
     * Build the upload rows for one month + cycle + bank filter.
     *
     * Only PROCESSED payroll records are included.
     */
    private function buildRows(
        string $month,
        string $payDay,
        string $bankFilter
    ): array {
        $this->skippedCount = 0;
        $this->skippedDetails = [];

        $query = PayrollRecord::query()
            ->with([
                'employee.bank',
                'user.employee.bank',
            ])
            ->where('month', $month)
            ->where('pay_day', $payDay)
            ->where('status', 'processed');

        $records = $query
            ->get()
            ->filter(function ($record) {
                return $this->employeeFor($record) !== null;
            })
            ->sortBy(function ($record) {
                return $this->employeeSortKey(
                    $this->employeeFor($record)
                );
            })
            ->values();

        $rows = [];

        foreach ($records as $record) {
            /** @var Employee $employee */
            $employee = $this->employeeFor($record);

            $bank = $employee->bank
                ?? (
                    $employee->bank_name
                        ? Bank::where(
                            'name',
                            $employee->bank_name
                        )->first()
                        : null
                );

            /*
             * The bank filter indicates the upload sheet format/target
             * bank system, not a filter to exclude employees.
             */
            $accountNumber =
                BankAccountFormatService::sanitizeAccountNumber(
                    $employee->account_number
                );

            $name =
                BankAccountFormatService::sanitizeAlphanumeric(
                    $employee->full_name,
                    25
                );

            /*
             * Validate the account number using the existing
             * bank-specific rules.
             */
            [
                $accountIsValid,
                $accountError
            ] = BankAccountFormatService::validateAccountNumber(
                $accountNumber,
                $bank
            );

            if (
                $accountNumber === ''
                || $name === ''
                || !$accountIsValid
            ) {
                $this->skippedCount++;

                $this->skippedDetails[] = [
                    'employee_code' =>
                        (string) (
                            $employee->employee_code ?? ''
                        ),

                    'reason' =>
                        $accountNumber === ''
                            ? 'Missing bank account number'
                            : (
                                $name === ''
                                    ? 'Missing employee name'
                                    : $accountError
                            ),
                ];

                continue;
            }

            $rows[] = [
                'employee_id' => $employee->id,

                'employee_code' =>
                    (string) (
                        $employee->employee_code ?? ''
                    ),

                'employee_name' =>
                    (string) (
                        $employee->full_name ?? ''
                    ),

                'bank_name' =>
                    $bank?->name
                    ?? (
                        (string) (
                            $employee->bank_name ?? ''
                        )
                    ),

                /*
                 * TYPE must eventually be written as a NUMBER
                 * in the Excel template.
                 */
                'type' =>
                    $bank && $bank->is_commercial
                        ? '1'
                        : '2',

                'to_account' => $accountNumber,

                'amount' =>
                    BankAccountFormatService::formatAmount(
                        (float) $record->net
                    ),

                'beneficiary_description' =>
                    BankAccountFormatService::sanitizeAlphanumeric(
                        $employee->employee_code,
                        30
                    ),

                'beneficiary_name' => $name,

                'beneficiary_id' =>
                    BankAccountFormatService::sanitizeAlphanumeric(
                        $employee->id_number,
                        30
                    ),

                'swift_code' =>
                    (string) (
                        $bank?->swift_code ?? ''
                    ),
            ];
        }

        return $rows;
    }

    /**
     * Create sender description.
     *
     * Example:
     *
     * Company Salaries [August 2026]
     */
    private function senderDescription(
        string $month
    ): string {
        $period = Carbon::createFromFormat(
            'Y-m',
            $month
        );

        $label = sprintf(
            'Company Salaries [%s]',
            $period->format('F Y')
        );

        return BankAccountFormatService::sanitizeAlphanumeric(
            $label,
            30
        );
    }

    /**
     * Resolve purpose code.
     *
     * @return array{
     *     code:string,
     *     description:string
     * }
     */
    private function resolvePurposeCode(
        ?string $code
    ): array {
        $purpose = $code
            ? PurposeCode::where(
                'code',
                $code
            )->first()
            : PurposeCode::where(
                'is_default',
                true
            )->first();

        if (
            $purpose === null
            && $code !== null
        ) {
            $purpose = PurposeCode::where(
                'code',
                $code
            )->first();
        }

        if ($purpose === null) {
            $purpose = PurposeCode::where(
                'code',
                self::DEFAULT_PURPOSE_CODE
            )->first();
        }

        return [
            'code' =>
                $purpose?->code
                ?? self::DEFAULT_PURPOSE_CODE,

            'description' =>
                $purpose?->description
                ?? 'Consultancy Fees, Legal Charges and Salaries',
        ];
    }

    /**
     * Resolve employee for a payroll record.
     *
     * The record's employee_id is authoritative.
     * The user -> employee relation is a fallback for legacy records.
     */
    private function employeeFor(
        PayrollRecord $record
    ): ?Employee {
        return $record->employee
            ?? $record->user?->employee;
    }

    /**
     * Sort employees by employee code.
     */
    private function employeeSortKey(
        Employee $employee
    ): array {
        $code = trim(
            (string) (
                $employee->employee_code ?? ''
            )
        );

        /*
         * Numeric employee codes first.
         */
        $isPurelyNumeric =
            preg_match(
                '/^[0-9]+$/',
                $code
            ) === 1
                ? 0
                : 1;

        /*
         * Extract the first number.
         */
        $number =
            preg_match(
                '/[0-9]+/',
                $code,
                $m
            )
                ? (int) $m[0]
                : PHP_INT_MAX;

        return [
            $isPurelyNumeric,
            $number,
            $code,
        ];
    }

    /**
     * Normalize month input.
     *
     * Supports:
     * YYYY-MM
     * YYYYMM
     * Other Carbon-recognized date formats.
     */
    private function normalizeMonth(
        string $raw
    ): ?string {
        $raw = trim($raw);

        if (
            preg_match(
                '/^\d{4}-\d{2}$/',
                $raw
            )
        ) {
            return $raw;
        }

        if (
            preg_match(
                '/^\d{6}$/',
                $raw
            )
        ) {
            return substr($raw, 0, 4)
                . '-'
                . substr($raw, 4, 2);
        }

        try {
            return Carbon::parse($raw)->format('Y-m');
        } catch (\Exception $e) {
            return null;
        }
    }
}
