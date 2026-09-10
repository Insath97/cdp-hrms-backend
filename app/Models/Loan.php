<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'loan_type',
        'description',
        'total_amount',
        'monthly_installment',
        'remaining_amount',
        'start_date',
        'end_date',
        'status',
        'approval_status',
        'rejection_reason',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'monthly_installment' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deductions()
    {
        return $this->hasMany(PayrollDeduction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
