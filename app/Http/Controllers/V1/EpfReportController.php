<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollRecord;
use App\Models\PayrollDeduction;
use App\Services\LoanDeductionService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EpfReportController extends Controller
{
    /**
     * EPF Report CSV.
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

        // All employees whose employee_code does NOT contain "RT" (case-sensitive matching as per spec: "dont have RT")
        $employees = Employee::with(['designation', 'salaryDetail'])
            ->whereRaw("employee_code NOT LIKE '%RT%'")
            ->orderBy('employee_code')
            ->get();

        $rows = [];
        foreach ($employees as $emp) {
            $basic = $this->resolveBasicSalary($emp, $period);

            $totalContribution = round($basic * 0.20, 2);
            $employerContribution = round($basic * 0.12, 2);
            $memberContribution = round($basic * 0.08, 2);

            $deductions = $this->resolveDeductions($emp, $periodYm);
            $totalEarnings = round(max(0, $basic - $deductions), 2);

            $memberStatus = $this->resolveMemberStatus($emp, $period);
            $surname = $this->resolveSurname($emp);
            $initials = $this->resolveInitials($emp);

            // Format money as 2 decimals with dot, no thousands separator for CSV numeric fields
            $fmt = fn($v) => number_format((float) $v, 2, '.', '');

            $rows[] = [
                'nic' => (string) ($emp->id_number ?? ''),
                'surname' => $surname,
                'initials' => $initials,
                'member_number' => (string) ($emp->employee_code ?? ''),
                'total_contribution' => $fmt($totalContribution),
                'employer_contribution' => $fmt($employerContribution),
                'member_contribution' => $fmt($memberContribution),
                'total_earnings' => $fmt($totalEarnings),
                'member_status' => $memberStatus,
                'zone' => 'A',
                'employer_number' => $employerNumber,
                'contribution_period' => $periodYyyymm,
                'data_submission_number' => '1',
                'no_of_days_worked' => '30',
                'occupation_grade' => $occupationGrade,
            ];
        }

        $headers = [
            'NIC Number',
            'Surname',
            'Initials',
            'Member Number',
            'Total Contribution',
            'Employer\'s Contribution',
            'Member\'s contribution',
            'Total Earnings',
            'Member Status',
            'Zone',
            'Employer Number',
            'Contribution Period',
            'Data Submission Number',
            'No of Days Worked',
            'Occupation Classification Grade',
        ];

        $filename = "EPF_Report_{$periodYyyymm}.csv";

        $callback = function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, $headers);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['nic'],
                    $r['surname'],
                    $r['initials'],
                    $r['member_number'],
                    $r['total_contribution'],
                    $r['employer_contribution'],
                    $r['member_contribution'],
                    $r['total_earnings'],
                    $r['member_status'],
                    $r['zone'],
                    $r['employer_number'],
                    $r['contribution_period'],
                    $r['data_submission_number'],
                    $r['no_of_days_worked'],
                    $r['occupation_grade'],
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

        $employees = Employee::with(['designation'])
            ->whereRaw("employee_code NOT LIKE '%RT%'")
            ->orderBy('employee_code')
            ->get();

        $rows = [];
        foreach ($employees as $emp) {
            $basic = $this->resolveBasicSalary($emp, $period);
            $deductions = $this->resolveDeductions($emp, $periodYm);
            $rows[] = [
                'id' => $emp->id,
                'nic' => (string)($emp->id_number ?? ''),
                'surname' => $this->resolveSurname($emp),
                'initials' => $this->resolveInitials($emp),
                'member_number' => (string)($emp->employee_code ?? ''),
                'basic_salary' => round($basic,2),
                'total_contribution' => round($basic*0.20,2),
                'employer_contribution' => round($basic*0.12,2),
                'member_contribution' => round($basic*0.08,2),
                'total_earnings' => round(max(0, $basic - $deductions),2),
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
        $surname = trim((string)($emp->l_name ?? ''));
        if ($surname !== '') return $surname;
        $full = trim((string)($emp->full_name ?? ''));
        if ($full === '') return '';
        $parts = preg_split('/\s+/', $full);
        return end($parts) ?: $full;
    }

    private function resolveInitials(Employee $emp): string
    {
        $initials = trim((string)($emp->name_with_initials ?? ''));
        if ($initials !== '') return $initials;
        $full = trim((string)($emp->full_name ?? ''));
        if ($full === '') return '';
        $parts = preg_split('/\s+/', $full);
        if (count($parts) >= 2) {
            $last = array_pop($parts);
            $inits = array_map(fn($p) => mb_substr($p, 0, 1) . '.', $parts);
            return implode(' ', $inits) . ' ' . $last;
        }
        return $full;
    }
}
