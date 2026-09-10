<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'basic_salary',
        'travel_reimbursement',
        'vehicle_rental',
        'performance_allowance',
        'incentive',
        'position_allowance',
        'mobile_payment',
        'monthly_target',
        'total_package',
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'travel_reimbursement' => 'decimal:2',
        'vehicle_rental' => 'decimal:2',
        'performance_allowance' => 'decimal:2',
        'incentive' => 'decimal:2',
        'position_allowance' => 'decimal:2',
        'mobile_payment' => 'decimal:2',
        'monthly_target' => 'decimal:2',
        'total_package' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($detail) {
            $detail->total_package =
                (float) ($detail->basic_salary ?? 0) +
                (float) ($detail->travel_reimbursement ?? 0) +
                (float) ($detail->vehicle_rental ?? 0) +
                (float) ($detail->performance_allowance ?? 0) +
                (float) ($detail->incentive ?? 0) +
                (float) ($detail->position_allowance ?? 0);
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
