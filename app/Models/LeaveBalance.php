<?php
// app/Models/LeaveBalance.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $table = 'leave_balances';

    protected $fillable = [
        'user_id',
        'employee_id',
        'leave_type_id',
        'year',
        'allocated',
        'used',
        'remaining',
        'pending'
    ];

    protected $casts = [
        'allocated' => 'decimal:2',
        'used' => 'decimal:2',
        'remaining' => 'decimal:2',
        'pending' => 'decimal:2',
        'year' => 'integer'
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    // Helper methods
    public function hasSufficientBalance($days): bool
    {
        return $this->remaining >= $days;
    }

    public function deductBalance($days)
    {
        $this->used += $days;
        $this->remaining = $this->allocated - $this->used;
        $this->save();
        return $this;
    }

    public function addToPending($days)
    {
        $this->pending += $days;
        $this->save();
        return $this;
    }

    public function removeFromPending($days)
    {
        $this->pending -= $days;
        if ($this->pending < 0) $this->pending = 0;
        $this->save();
        return $this;
    }

    public function addBalance($days, $type = 'allocated')
    {
        if ($type === 'allocated') {
            $this->allocated += $days;
        }
        $this->remaining = $this->allocated - $this->used;
        $this->save();
        return $this;
    }

    // Get identifier
    public function getIdentifier()
    {
        return $this->employee_id ?? $this->user_id;
    }
}
