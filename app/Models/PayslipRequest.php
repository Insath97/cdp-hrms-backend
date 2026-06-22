<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayslipRequest extends Model
{
    protected $fillable = [
        'user_id', 'employee_id', 'payroll_record_id', 'period', 'month_label',
        'status', 'reason', 'rejection_reason', 'approved_by', 'approved_at', 
        'signed_file_path'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payrollRecord()
    {
        return $this->belongsTo(PayrollRecord::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}