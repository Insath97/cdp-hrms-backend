<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRecord extends Model
{
    protected $fillable = [
        'user_id', 'employee_id', 'designation_id', 'designation_name', 'month',
        'basic', 'allowances', 'travel_reimbursement', 'vehicle_rental',
        'performance_allowance', 'incentive', 'position_allowance',
        'mobile_payment', 'monthly_target', 'total_package',
        'achievement_percentage', 'payment_percentage', 'payment_criteria',
        'calculated_payment', 'mobile_payment_bonus',
        'commission', 'override_commission', 'total_commission',
        'how_much_paid',
        'epf_employee', 'epf_employer', 'etf_employer',
        'paye_tax', 'wht_tax', 'recover_amount', 'loan_deductions', 'advance_deductions',
        'total_deductions', 'net',
        'status', 'file_path', 'processed_at',
    ];

    protected $casts = [
        'basic' => 'decimal:2',
        'allowances' => 'decimal:2',
        'travel_reimbursement' => 'decimal:2',
        'vehicle_rental' => 'decimal:2',
        'performance_allowance' => 'decimal:2',
        'incentive' => 'decimal:2',
        'position_allowance' => 'decimal:2',
        'mobile_payment' => 'decimal:2',
        'monthly_target' => 'decimal:2',
        'total_package' => 'decimal:2',
        'achievement_percentage' => 'decimal:2',
        'payment_percentage' => 'decimal:2',
        'calculated_payment' => 'decimal:2',
        'mobile_payment_bonus' => 'decimal:2',
        'commission' => 'decimal:2',
        'override_commission' => 'decimal:2',
        'total_commission' => 'decimal:2',
        'how_much_paid' => 'decimal:2',
        'epf_employee' => 'decimal:2',
        'epf_employer' => 'decimal:2',
        'etf_employer' => 'decimal:2',
        'paye_tax' => 'decimal:2',
        'wht_tax' => 'decimal:2',
        'recover_amount' => 'decimal:2',
        'loan_deductions' => 'decimal:2',
        'advance_deductions' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function payslipRequests()
    {
        return $this->hasMany(PayslipRequest::class);
    }

    public function latestRequest()
    {
        return $this->hasOne(PayslipRequest::class)->latest();
    }

    public function deductions()
    {
        return $this->hasMany(PayrollDeduction::class);
    }
}
