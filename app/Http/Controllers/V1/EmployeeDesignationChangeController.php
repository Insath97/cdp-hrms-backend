<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeDesignationChange;
use App\Models\EmployeeSalaryDetail;
use App\Traits\ActivityLogTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeDesignationChangeController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Salary components that can be revised when a designation changes.
     */
    private const SALARY_FIELDS = [
        'basic_salary',
        'travel_reimbursement',
        'vehicle_rental',
        'performance_allowance',
        'incentive',
        'position_allowance',
        'mobile_payment',
        'monthly_target',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Employee Index', only: ['index']),
            new Middleware('permission:Employee Update', only: ['store']),
        ];
    }

    /**
     * Designation / salary change history for an employee.
     */
    public function index(int|string $employeeId)
    {
        try {
            Employee::findOrFail($employeeId);

            $changes = EmployeeDesignationChange::with([
                'previousDesignation:id,name,level',
                'newDesignation:id,name,level',
                'createdBy:id,name',
            ])
                ->where('employee_id', $employeeId)
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->get()
                ->map(function (EmployeeDesignationChange $change) {
                    return array_merge($change->toArray(), [
                        'created_by_name' => $change->createdBy?->name,
                    ]);
                });

            return response()->json([
                'status' => 'success',
                'data' => $changes,
            ]);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve designation change history', [
                'employee_id' => $employeeId,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve designation change history',
            ], 500);
        }
    }

    /**
     * Change an employee's designation and, optionally, their salary.
     *
     * This keeps the employee record, salary history and designation change
     * history in sync inside a single transaction.
     */
    public function store(Request $request, int|string $employeeId)
    {
        $validated = $request->validate([
            'new_designation_id' => 'required|exists:designations,id',
            'change_type' => 'nullable|in:promotion,demotion,lateral,revision,other',
            'effective_date' => 'required|date',
            'reason' => 'nullable|string|max:1000',
            'department_id' => 'nullable|exists:departments,id',
            'use_designation_salary' => 'sometimes|boolean',
            'basic_salary' => 'sometimes|nullable|numeric|min:0',
            'travel_reimbursement' => 'sometimes|nullable|numeric|min:0',
            'vehicle_rental' => 'sometimes|nullable|numeric|min:0',
            'performance_allowance' => 'sometimes|nullable|numeric|min:0',
            'incentive' => 'sometimes|nullable|numeric|min:0',
            'position_allowance' => 'sometimes|nullable|numeric|min:0',
            'mobile_payment' => 'sometimes|nullable|numeric|min:0',
            'monthly_target' => 'sometimes|nullable|numeric|min:0',
        ]);

        try {
            return DB::transaction(function () use ($validated, $employeeId) {
                /** @var Employee $employee */
                $employee = Employee::lockForUpdate()->findOrFail($employeeId);
                $previousDesignation = $employee->designation;
                $newDesignation = Designation::findOrFail($validated['new_designation_id']);

                $effectiveDate = Carbon::parse($validated['effective_date'])->startOfDay();
                $activeSalary = EmployeeSalaryDetail::where('employee_id', $employee->id)
                    ->whereNull('effective_to')
                    ->latest('effective_from')
                    ->first();

                if ($activeSalary && $effectiveDate->lt($activeSalary->effective_from->copy()->startOfDay())) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Effective date cannot be earlier than the current salary effective date ('
                            . $activeSalary->effective_from->format('Y-m-d') . ').',
                    ], 422);
                }

                $previousValues = $this->salaryValues($activeSalary, $previousDesignation);
                $newValues = $this->buildNewSalaryValues($validated, $newDesignation);

                $previousTotal = $activeSalary?->total_package !== null
                    ? (float) $activeSalary->total_package
                    : $this->totalPackage($previousValues);
                $newTotal = $this->totalPackage($newValues);

                $designationChanged = (int) $employee->designation_id !== (int) $newDesignation->id;
                $salaryChanged = $this->salaryValuesDiffer($previousValues, $newValues);

                if (! $designationChanged && ! $salaryChanged) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'The designation and salary are unchanged. Nothing to update.',
                    ], 422);
                }

                // Close the salary record that is active before the new change.
                if ($activeSalary) {
                    $closeDate = $effectiveDate->copy()->subDay();
                    $activeSalary->update(['effective_to' => $closeDate->toDateString()]);
                }

                // Create a new salary history row so payroll uses the new package.
                $newSalaryDetail = EmployeeSalaryDetail::create(array_merge($newValues, [
                    'employee_id' => $employee->id,
                    'designation_id' => $newDesignation->id,
                    'designation_name' => $newDesignation->name,
                    'effective_from' => $effectiveDate->toDateString(),
                ]));

                $employeeData = ['designation_id' => $newDesignation->id];
                if (! empty($validated['department_id'])) {
                    $employeeData['department_id'] = $validated['department_id'];
                }
                $employee->update($employeeData);

                $changeType = $validated['change_type']
                    ?? $this->detectChangeType($previousDesignation, $newDesignation, $previousTotal, $newTotal);

                $changeAmount = round($newTotal - $previousTotal, 2);
                $changePercentage = $previousTotal > 0
                    ? round(($changeAmount / $previousTotal) * 100, 2)
                    : null;

                $change = EmployeeDesignationChange::create([
                    'employee_id' => $employee->id,
                    'previous_designation_id' => $previousDesignation?->id,
                    'previous_designation_name' => $previousDesignation?->name,
                    'new_designation_id' => $newDesignation->id,
                    'new_designation_name' => $newDesignation->name,
                    'change_type' => $changeType,
                    'effective_date' => $effectiveDate->toDateString(),
                    'previous_total_package' => $previousTotal,
                    'new_total_package' => $newTotal,
                    'salary_change_amount' => $changeAmount,
                    'salary_change_percentage' => $changePercentage,
                    'previous_salary_detail_id' => $activeSalary?->id,
                    'new_salary_detail_id' => $newSalaryDetail->id,
                    'reason' => $validated['reason'] ?? null,
                    'created_by' => Auth::id(),
                ]);

                $this->logActivity(
                    'UPDATE',
                    'Employee Designation',
                    "{$changeType} for {$employee->full_name}: "
                        . ($previousDesignation?->name ?? 'No designation')
                        . " -> {$newDesignation->name}",
                    [
                        'employee_id' => $employee->id,
                        'change_id' => $change->id,
                        'previous_designation' => $previousDesignation?->name,
                        'new_designation' => $newDesignation->name,
                        'previous_total_package' => $previousTotal,
                        'new_total_package' => $newTotal,
                    ]
                );

                $change->load([
                    'previousDesignation:id,name,level',
                    'newDesignation:id,name,level',
                    'newSalaryDetail',
                    'createdBy:id,name',
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Designation and salary updated successfully',
                    'data' => array_merge($change->toArray(), [
                        'created_by_name' => $change->createdBy?->name,
                    ]),
                ], 201);
            });
        } catch (\Throwable $th) {
            Log::error('Failed to change employee designation', [
                'employee_id' => $employeeId,
                'user_id' => Auth::id(),
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update designation and salary: ' . $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve the current salary values, falling back to designation defaults.
     */
    private function salaryValues(?EmployeeSalaryDetail $detail, ?Designation $designation): array
    {
        $values = [];

        foreach (self::SALARY_FIELDS as $field) {
            if ($detail && ! is_null($detail->{$field})) {
                $values[$field] = (float) $detail->{$field};
            } else {
                $values[$field] = (float) ($designation?->{$field} ?? 0);
            }
        }

        return $values;
    }

    /**
     * Build the new salary package from the submitted values, using the new
     * designation's defaults for any component that was not supplied.
     */
    private function buildNewSalaryValues(array $validated, Designation $designation): array
    {
        $values = [];
        $useDefaults = (bool) ($validated['use_designation_salary'] ?? false);

        foreach (self::SALARY_FIELDS as $field) {
            if (! $useDefaults && array_key_exists($field, $validated) && ! is_null($validated[$field])) {
                $values[$field] = (float) $validated[$field];
            } else {
                $values[$field] = (float) ($designation->{$field} ?? 0);
            }
        }

        return $values;
    }

    private function totalPackage(array $values): float
    {
        // total_package intentionally excludes mobile_payment and monthly_target,
        // matching EmployeeSalaryDetail / Designation calculations.
        return round(
            (float) ($values['basic_salary'] ?? 0)
            + (float) ($values['travel_reimbursement'] ?? 0)
            + (float) ($values['vehicle_rental'] ?? 0)
            + (float) ($values['performance_allowance'] ?? 0)
            + (float) ($values['incentive'] ?? 0)
            + (float) ($values['position_allowance'] ?? 0),
            2
        );
    }

    private function salaryValuesDiffer(array $previous, array $new): bool
    {
        foreach (self::SALARY_FIELDS as $field) {
            if (round((float) ($previous[$field] ?? 0), 2) !== round((float) ($new[$field] ?? 0), 2)) {
                return true;
            }
        }

        return false;
    }

    private function detectChangeType(
        ?Designation $previous,
        Designation $new,
        float $previousTotal,
        float $newTotal
    ): string {
        if ($previous && (int) $previous->id === (int) $new->id) {
            return 'revision';
        }

        $previousRank = $previous?->levelRank() ?? 0;
        $newRank = $new->levelRank();

        if ($previousRank > 0 && $newRank > 0 && $previousRank !== $newRank) {
            return $newRank > $previousRank ? 'promotion' : 'demotion';
        }

        if ($newTotal !== $previousTotal) {
            return $newTotal > $previousTotal ? 'promotion' : 'demotion';
        }

        return 'lateral';
    }
}
