<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollRecord;
use App\Models\PayrollDeduction;
use App\Services\LoanDeductionService;
use App\Services\PayrollActivationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class EpfReportController extends Controller
{
    /**
     * Mirror the CUS report: expose nothing until the month has been activated
     * and its payroll processed. Returns a 403 response when blocked, or null
     * when the report may be shown.
     */
    private function guardMonth(string $periodYm): ?\Illuminate\Http\JsonResponse
    {
        if (! PayrollActivationService::canView($periodYm, Auth::user())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payroll for this month has not been activated yet.',
            ], 403);
        }

        if (! PayrollRecord::where('month', $periodYm)->where('status', 'processed')->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payroll has not been processed for this month yet.',
            ], 403);
        }

        return null;
    }
    /**
     * EPF Report Excel (.xlsx).
     * Filters employees whose employee_code does NOT contain "RT".
     * Query param: month = YYYY-MM or YYYYMM (Contribution Period). Defaults to current month.
     * Optional: employer_number, occupation_grade (placeholders).
     */
    public function index(Request $request): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $request->validate([
            'month' => 'nullable|string',
            'employer_number' => 'nullable|string|max:50',
            'occupation_grade' => 'nullable|string|max:50',
        ]);

        $rawMonth = $request->get('month') ?: now()->format('Y-m');
        $period = $this->normalizeMonth($rawMonth);
        if (!$period) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid month format. Use YYYY-MM or YYYYMM.',
            ], 422);
        }

        $periodYm = $period->format('Y-m');
        $periodYyyymm = $period->format('Ym');
        $employerNumber = $request->get('employer_number', '');
        $occupationGrade = $request->get('occupation_grade', '');

        if ($blocked = $this->guardMonth($periodYm)) {
            return $blocked;
        }

        // Active employees only (exclude inactive/terminated), whose employee_code does NOT contain "RT"
        $employees = Employee::with(['designation', 'salaryDetail'])
            ->where('is_active', true)
            ->whereRaw("employee_code NOT LIKE '%RT%'")
            ->orderByRaw("(CASE WHEN employee_code REGEXP '^[0-9]+$' THEN 0 ELSE 1 END) ASC, CAST(REGEXP_SUBSTR(employee_code, '[0-9]+') AS UNSIGNED) ASC, employee_code ASC")
            ->get();

        $headers = [
            'NIC Number',
            'Surname',
            'Initials',
            'Member Number',
            'Total Contribution',
            "Employer's Contribution",
            "Member's Contribution",
            'Total Earnings',
            'Member Status',
            'Zone',
            'Employer Number',
            'Contribution Period',
            'Data Submission Number',
            'No of Days Worked',
            'Occupation Classification Grade',
        ];

        // Build rows
        $rows = [];
        $fmt = fn($v) => number_format((float) $v, 2, '.', '');

        foreach ($employees as $emp) {
            $basic = $this->resolveBasicSalary($emp, $period);
            $totalContribution    = round($basic * 0.20, 2);
            $employerContribution = round($basic * 0.12, 2);
            $memberContribution   = round($basic * 0.08, 2);
            $deductions           = $this->resolveDeductions($emp, $periodYm);
            $totalEarnings        = round(max(0, $basic - $deductions), 2);

            $rows[] = [
                (string) ($emp->id_number ?? ''),
                // $this->resolveSurname($emp),
                // $this->resolveInitials($emp),
                $emp->surname,
                $emp->initials,
                (string) ($emp->employee_code ?? ''),
                $fmt($totalContribution),
                $fmt($employerContribution),
                $fmt($memberContribution),
                $fmt($totalEarnings),
                $this->resolveMemberStatus($emp, $period),
                'A',
                $employerNumber,
                $periodYyyymm,
                '1',
                '30',
                $occupationGrade,
            ];
        }

        // Build spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('EPF Report');

        // Write header row
        $sheet->fromArray($headers, null, 'A1');

        // Style header row: bold, background colour, center-aligned, border
        $headerRange = 'A1:O1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['argb' => 'FFFFFFFF'],
                'size'  => 11,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF1E6F9F'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['argb' => 'FFAAAAAA'],
                ],
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Write data rows cell-by-cell so we can force text type on
        // NIC Number (col A) and Member Number (col D), preventing Excel
        // from displaying long numeric NICs in scientific notation (e.g. 1.98E+11).
        // Note: getCellByColumnAndRow() was removed in PhpSpreadsheet 2+; use getCell() with coordinate strings.
        $columnMap = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O'];
        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 2; // data starts at row 2
            // Column A: NIC Number — always treat as text to prevent scientific notation
            $sheet->getCell('A' . $excelRow)
                  ->setValueExplicit((string) $row[0], DataType::TYPE_STRING);
            // Column B: Surname
            $sheet->getCell('B' . $excelRow)->setValue($row[1]);
            // Column C: Initials
            $sheet->getCell('C' . $excelRow)->setValue($row[2]);
            // Column D: Member Number — treat as text to preserve leading zeros / alphanumeric codes
            $sheet->getCell('D' . $excelRow)
                  ->setValueExplicit((string) $row[3], DataType::TYPE_STRING);
            // Columns E–O: remaining values (numeric/string auto-detected)
            foreach (array_slice($row, 4) as $colOffset => $value) {
                $sheet->getCell($columnMap[$colOffset + 4] . $excelRow)->setValue($value);
            }
        }

        // Style data rows: borders + alternating row colours
        $totalRows = count($rows);
        if ($totalRows > 0) {
            $dataRange = 'A2:O' . ($totalRows + 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color'       => ['argb' => 'FFDDDDDD'],
                    ],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);

            // Alternating row fill for readability
            for ($i = 2; $i <= $totalRows + 1; $i++) {
                if ($i % 2 === 0) {
                    $sheet->getStyle("A{$i}:O{$i}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFF0F7FC');
                }
            }
        }

        // Auto-fit column widths
        foreach (range('A', 'O') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Freeze the header row
        $sheet->freezePane('A2');

        $filename = "EPF_Report_{$periodYyyymm}.xlsx";

        $writer = new Xlsx($spreadsheet);

        $callback = function () use ($writer) {
            $writer->save('php://output');
        };

        return new StreamedResponse($callback, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
        ]);
    }

    /**
     * JSON preview (for frontend table) - same logic but returns JSON instead of CSV.
     */
    public function preview(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'month' => 'nullable|string',
        ]);
        $rawMonth = $request->get('month') ?: now()->format('Y-m');
        $period = $this->normalizeMonth($rawMonth);
        if (!$period) {
            return response()->json(['status'=>'error','message'=>'Invalid month format. Use YYYY-MM or YYYYMM.'],422);
        }
        $periodYm = $period->format('Y-m');
        $periodYyyymm = $period->format('Ym');

        if ($blocked = $this->guardMonth($periodYm)) {
            return $blocked;
        }

        $employees = Employee::with(['designation'])
            ->where('is_active', true)
            ->whereRaw("employee_code NOT LIKE '%RT%'")
            ->orderByRaw("(CASE WHEN employee_code REGEXP '^[0-9]+$' THEN 0 ELSE 1 END) ASC, CAST(REGEXP_SUBSTR(employee_code, '[0-9]+') AS UNSIGNED) ASC, employee_code ASC")
            ->get();

        $rows = [];
        $totals = [
            'members' => 0,
            'basic' => 0.0,
            'total_contribution' => 0.0,
            'employer' => 0.0,
            'member' => 0.0,
            'earnings' => 0.0,
        ];
        foreach ($employees as $emp) {
            $basic = $this->resolveBasicSalary($emp, $period);
            $deductions = $this->resolveDeductions($emp, $periodYm);
            $totalContribution = round($basic*0.20,2);
            $employerContribution = round($basic*0.12,2);
            $memberContribution = round($basic*0.08,2);
            $earnings = round(max(0, $basic - $deductions),2);

            $totals['members']++;
            $totals['basic'] += $basic;
            $totals['total_contribution'] += $totalContribution;
            $totals['employer'] += $employerContribution;
            $totals['member'] += $memberContribution;
            $totals['earnings'] += $earnings;

            $rows[] = [
                'id' => $emp->id,
                'nic' => (string)($emp->id_number ?? ''),
                // 'surname' => $this->resolveSurname($emp),
                // 'initials' => $this->resolveInitials($emp),
                'surname' => $emp->surname,
                'initials' => $emp->initials,
                'member_number' => (string)($emp->employee_code ?? ''),
                'basic_salary' => round($basic,2),
                'total_contribution' => $totalContribution,
                'employer_contribution' => $employerContribution,
                'member_contribution' => $memberContribution,
                'total_earnings' => $earnings,
                'deductions' => round($deductions,2),
                'member_status' => $this->resolveMemberStatus($emp, $period),
                'zone' => 'A',
                'contribution_period' => $periodYyyymm,
                'employment_status' => $emp->employment_status,
                'is_active' => (bool)$emp->is_active,
                'joined_at' => $emp->joined_at,
                'left_at' => $emp->left_at,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'month' => $periodYm,
                'contribution_period' => $periodYyyymm,
                'count' => count($rows),
                'total_members' => $totals['members'],
                'total_basic_salary' => round($totals['basic'], 2),
                'total_contribution' => round($totals['total_contribution'], 2),
                'total_employer_contribution' => round($totals['employer'], 2),
                'total_member_contribution' => round($totals['member'], 2),
                'total_earnings' => round($totals['earnings'], 2),
                'rows' => $rows,
            ]
        ]);
    }

    private function normalizeMonth(string $raw): ?Carbon
    {
        $raw = trim($raw);
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m', $raw)->startOfMonth();
        }
        if (preg_match('/^\d{6}$/', $raw)) {
            return Carbon::createFromFormat('Ym', $raw)->startOfMonth();
        }
        // also support YYYY/MM or month name parsing
        try {
            return Carbon::parse($raw)->startOfMonth();
        } catch (\Exception $e) {
            return null;
        }
    }

    private function resolveBasicSalary(Employee $emp, Carbon $period): float
    {
        // Active salary detail for period takes precedence
        $override = $emp->salaryDetail()
            ->activeForPeriod($period)
            ->first();
        if ($override && !is_null($override->basic_salary)) {
            return (float) $override->basic_salary;
        }
        if ($emp->designation && !is_null($emp->designation->basic_salary)) {
            return (float) $emp->designation->basic_salary;
        }
        return (float) ($emp->basic_salary ?? 0);
    }

    private function resolveDeductions(Employee $emp, string $periodYm): float
    {
        $loan = LoanDeductionService::activeForPeriod($emp->id, $periodYm);
        $loanTotal = (float) ($loan['total'] ?? 0);

        // Manual payroll deductions already approved for this employee's payroll records of that month
        $payrollRecordIds = PayrollRecord::where('employee_id', $emp->id)
            ->where('month', $periodYm)
            ->pluck('id');
        $manualTotal = 0.0;
        if ($payrollRecordIds->isNotEmpty()) {
            $manualTotal = (float) PayrollDeduction::whereIn('payroll_record_id', $payrollRecordIds)
                ->where('approval_status', 'approved')
                ->sum('amount');
        }

        return round($loanTotal + $manualTotal, 2);
    }

    private function resolveMemberStatus(Employee $emp, Carbon $period): string
    {
        // V if resignation/termination: left_at set or employment_status != active or is_active == false with termination
        $status = strtolower((string)($emp->employment_status ?? ''));
        $hasLeft = !empty($emp->left_at);
        if ($hasLeft || in_array($status, ['terminated','resigned','inactive','terminated_resigned'], true) || ($emp->is_active == false && $hasLeft)) {
            return 'V';
        }
        // Some codebases store resignation via termination_reason/left_at without status change - check left_at already
        if ($hasLeft) return 'V';

        // N if joined in the contribution period month
        try {
            if (!empty($emp->joined_at)) {
                $joined = Carbon::parse($emp->joined_at);
                if ($joined->format('Y-m') === $period->format('Y-m')) {
                    return 'N';
                }
            }
        } catch (\Exception $e) {
            // ignore parse failure
        }

        return 'E';
    }

    private function resolveSurname(Employee $emp): string
    {
        $surname = trim((string)($emp->surname ?? ''));
        if ($surname !== '') return $surname;
        $surname = trim((string)($emp->l_name ?? ''));
        if ($surname !== '') return $surname;
        $full = trim((string)($emp->full_name ?? ''));
        if ($full === '') return '';
        $parts = preg_split('/\s+/', $full);
        return end($parts) ?: $full;
    }

    private function resolveInitials(Employee $emp): string
    {
        $initials = trim((string)($emp->initials ?? ''));
        if ($initials !== '') return $initials;
        return trim((string)($emp->name_with_initials ?? ''));
    }
}
