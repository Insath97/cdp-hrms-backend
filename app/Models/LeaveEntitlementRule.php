<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveEntitlementRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'service_year_from',
        'service_year_to',
        'join_month_from',
        'join_month_to',
        'entitlement_days',
        'accrual_type',
        'max_days',
        'is_active',
    ];

    protected $casts = [
        'entitlement_days' => 'decimal:2',
        'max_days' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
