<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollDeduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_record_id',
        'type',
        'label',
        'amount',
        'loan_id',
        'is_auto',
        'approval_status',
        'rejection_reason',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_auto' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function payrollRecord(): BelongsTo
    {
        return $this->belongsTo(PayrollRecord::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
