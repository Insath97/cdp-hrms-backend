<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRecord extends Model
{
    protected $fillable = [
        'user_id', 'month', 'basic', 'allowances', 'deductions', 
        'net', 'epf_employee', 'epf_employer', 'etf_employer', 
        'status', 'file_path', 'processed_at'
    ];

    protected $casts = [
        'basic' => 'decimal:2',
        'allowances' => 'decimal:2',
        'deductions' => 'decimal:2',
        'net' => 'decimal:2',
        'epf_employee' => 'decimal:2',
        'epf_employer' => 'decimal:2',
        'etf_employer' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payslipRequests()
    {
        return $this->hasMany(PayslipRequest::class);
    }

    public function latestRequest()
    {
        return $this->hasOne(PayslipRequest::class)->latest();
    }
}