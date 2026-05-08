<?php
// app/Models/Leave.php - Add these methods

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Leave extends Model
{
    protected $fillable = [
        'employee_id',
        'user_id',
        'leave_type_id',
        'from_date',
        'to_date',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
        'is_auto_converted',
        'consecutive_late_days',
        'grace_period_at_conversion'
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'is_auto_converted' => 'boolean'
    ];

    // Relationships
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeAutoConverted($query)
    {
        return $query->where('is_auto_converted', true);
    }

    // Helper methods
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function canApprove(): bool
    {
        return $this->isPending();
    }

    public function canReject(): bool
    {
        return $this->isPending();
    }

    public function calculateDays()
    {
        $start = Carbon::parse($this->from_date);
        $end = Carbon::parse($this->to_date);
        return $start->diffInDays($end) + 1;
    }

    public function getBalanceIdentifier()
    {
        return $this->employee_id ?? $this->user_id;
    }
}
