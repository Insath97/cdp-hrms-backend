<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollMonth extends Model
{
    protected $fillable = [
        'month', 'status', 'activated_by', 'activated_at', 'locked_by', 'locked_at',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function activator()
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function locker()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }
}
