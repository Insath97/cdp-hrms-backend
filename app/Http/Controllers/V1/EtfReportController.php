<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\LoanDeductionService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EtfReportController extends Controller
{
    /**
     * ETF Report CSV.
     * Employees whose employee_code does NOT contain "RT".
     * ?month=YYYY-MM|YYYYMM (defaults current month).
     */
    public function index(Request $request): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $request->validate([
            'month' => 'nullable|string',
        ]);

        $rawMonth = $request->get('month') ?: now()->format('Y-m');
        $period = $this->normalizeMonth($rawMonth);
        if (!$period) {
            return response()->json(['status' => 'error', 'message' => 'Invalid month format. Use YYYY-MM or YYYYMM.'], 422);
        }
        $periodYm = $period->format('Y-m');
        $periodYyyymm = $period->format('Ym');
        $year = $period->format('Y');
        $monthNum = $period->format('m');

        $employees = Employee::with(['designation', 'salaryDetail'])
            ->whereRaw("employee_code NOT LIKE '%RT%'")
            ->orderByRaw("(CASE WHEN employee_code REGEXP '^[0-9]+$' THEN 0 ELSE 1 END) ASC, CAST(REGEXP_SUBSTR(employee_code, '[0-9]+') AS UNSIGNED) ASC, employee_code ASC")
            ->get();

        $rows = [];
        foreach ($employees as $emp) {
            $basic = $this->resolveBasicSalary($emp, $period);
            $contribution = round($basic * 0.03, 2);

            $fmt = fn($v) => number_format((float) $v, 2, '.', '');

            $rows[] = [
                'member_number' => (string) ($emp->employee_code ?? ''),
                'initials' => $this->resolveInitials($emp),
                'surname' => $this->resolveSurname($emp),
                'nic' => (string) ($emp->id_number ?? ''),
                'contribution' => $fmt($contribution),
            ];
        }

        $headers = ['Member Number', 'Member Initials', "Member's Surname", 'NIC', 'Contribution'];

        $filename = "ETF_Report_{$periodYyyymm}.csv";

        $callback = function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, $headers);
            foreach ($rows as $r) {
                fputcsv($out, [$r['member_number'], $r['initials'], $r['surname'], $r['nic'], $r['contribution']]);
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
     * JSON preview.
     */
    public function preview(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['month' => 'nullable|string']);
        $rawMonth = $request->get('month') ?: now()->format('Y-m');
        $period = $this->normalizeMonth($rawMonth);
        if (!$period) {
            return response()->json(['status'=>'error','message'=>'Invalid month format. Use YYYY-MM or YYYYMM.'],422);
        }
        $periodYm = $period->format('Y-m');
        $periodYyyymm = $period->format('Ym');
        $year = $period->format('Y');
        $monthNum = $period->format('m');

        $employees = Employee::with(['designation'])
            ->whereRaw("employee_code NOT LIKE '%RT%'")
            ->orderByRaw("(CASE WHEN employee_code REGEXP '^[0-9]+$' THEN 0 ELSE 1 END) ASC, CAST(REGEXP_SUBSTR(employee_code, '[0-9]+') AS UNSIGNED) ASC, employee_code ASC")
            ->get();

        $totalMembers = 0;
        $totalContribution = 0.0;
        $rows = [];
        foreach ($employees as $emp) {
            $basic = $this->resolveBasicSalary($emp, $period);
            $contribution = round($basic * 0.03, 2);
            $totalContribution += $contribution;
            $totalMembers++;
            $rows[] = [
                'id' => $emp->id,
                'member_number' => (string)($emp->employee_code ?? ''),
                'initials' => $this->resolveInitials($emp),
                'surname' => $this->resolveSurname($emp),
                'nic' => (string)($emp->id_number ?? ''),
                'basic_salary' => round($basic, 2),
                'contribution' => $contribution,
                'member_status' => $this->resolveMemberStatus($emp, $period),
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'month' => $periodYm,
                'contribution_period' => $periodYyyymm,
                'from_year' => (string) $year,
                'from_month' => $monthNum,
                'to_year' => (string) $year,
                'to_month' => $monthNum,
                'members' => $totalMembers,
                'total_members' => $totalMembers,
                'total_contribution' => round($totalContribution, 2),
                'rows' => $rows,
            ]
        ]);
    }

    private function normalizeMonth(string $raw): ?Carbon
    {
        $raw = trim($raw);
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) return Carbon::createFromFormat('Y-m', $raw)->startOfMonth();
        if (preg_match('/^\d{6}$/', $raw)) return Carbon::createFromFormat('Ym', $raw)->startOfMonth();
        try { return Carbon::parse($raw)->startOfMonth(); } catch (\Exception $e) { return null; }
    }

    private function resolveBasicSalary(Employee $emp, Carbon $period): float
    {
        $override = $emp->salaryDetail()->activeForPeriod($period)->first();
        if ($override && !is_null($override->basic_salary)) return (float) $override->basic_salary;
        if ($emp->designation && !is_null($emp->designation->basic_salary)) return (float) $emp->designation->basic_salary;
        return (float) ($emp->basic_salary ?? 0);
    }

    private function resolveMemberStatus(Employee $emp, Carbon $period): string
    {
        $status = strtolower((string)($emp->employment_status ?? ''));
        $hasLeft = !empty($emp->left_at);
        if ($hasLeft || in_array($status, ['terminated','resigned','inactive'], true) || ($emp->is_active == false && $hasLeft)) return 'V';
        if ($hasLeft) return 'V';
        if (!empty($emp->joined_at)) {
            try { if (Carbon::parse($emp->joined_at)->format('Y-m') === $period->format('Y-m')) return 'N'; } catch (\Exception $e) {}
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
        return trim((string)($emp->name_with_initials ?? ''));
    }
}
