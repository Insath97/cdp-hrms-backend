<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_record_id',
        'basic_salary',
        'travel_reimbursement',
        'vehicle_rental',
        'performance_allowance',
        'incentive',
        'position_allowance',
        'mobile_payment',
        'monthly_target',
        'total_package',
        'achievement_percentage',
        'payment_percentage',
        'payment_criteria',
        'calculated_payment',
        'commission',
        'override_commission',
        'total_commission',
        'total_epf_employee',
        'total_epf_employer',
        'total_etf_employer',
        'total_deductions',
        'net_pay',
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'total_package' => 'decimal:2',
        'achievement_percentage' => 'decimal:2',
        'payment_percentage' => 'decimal:2',
        'calculated_payment' => 'decimal:2',
        'commission' => 'decimal:2',
        'override_commission' => 'decimal:2',
        'total_commission' => 'decimal:2',
        'total_epf_employee' => 'decimal:2',
        'total_epf_employer' => 'decimal:2',
        'total_etf_employer' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
    ];

    public function payrollRecord(): BelongsTo
    {
        return $this->belongsTo(PayrollRecord::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(PayrollDeduction::class);
    }
}
