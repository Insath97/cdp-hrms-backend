<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class EmployeeSalaryDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'designation_id',
        'designation_name',
        'basic_salary',
        'travel_reimbursement',
        'vehicle_rental',
        'performance_allowance',
        'incentive',
        'position_allowance',
        'mobile_payment',
        'monthly_target',
        'total_package',
        'effective_from',
        'effective_to',
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
        'effective_from' => 'date',
        'effective_to' => 'date',
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

    public function scopeActiveForPeriod($query, Carbon $date)
    {
        return $query->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            });
    }

    public function isActive(): bool
    {
        $now = Carbon::now();
        return $this->effective_from->lte($now)
            && (is_null($this->effective_to) || $this->effective_to->gte($now));
    }
}
