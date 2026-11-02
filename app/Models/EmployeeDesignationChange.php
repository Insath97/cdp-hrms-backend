<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDesignationChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'previous_designation_id',
        'previous_designation_name',
        'new_designation_id',
        'new_designation_name',
        'change_type',
        'effective_date',
        'previous_total_package',
        'new_total_package',
        'salary_change_amount',
        'salary_change_percentage',
        'previous_salary_detail_id',
        'new_salary_detail_id',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'previous_total_package' => 'decimal:2',
        'new_total_package' => 'decimal:2',
        'salary_change_amount' => 'decimal:2',
        'salary_change_percentage' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function previousDesignation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'previous_designation_id');
    }

    public function newDesignation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'new_designation_id');
    }

    public function previousSalaryDetail(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalaryDetail::class, 'previous_salary_detail_id');
    }

    public function newSalaryDetail(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalaryDetail::class, 'new_salary_detail_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
