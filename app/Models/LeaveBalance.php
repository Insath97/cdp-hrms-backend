<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'employee_id',
        'leave_type_id',
        'entitled',
        'allocated',
        'used',
        'balance',
        'year',
        'last_accrued_at',
    ];

    protected $casts = [
        'entitled' => 'decimal:2',
        'allocated' => 'decimal:2',
        'used' => 'decimal:2',
        'balance' => 'decimal:2',
        'last_accrued_at' => 'date:Y-m-d',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }
}
