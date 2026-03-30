<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'calculation_unit',
        'default_allocation',
        'color_code',
        'description',
        'is_paid',
        'is_active',
        'is_pregnancy_related',
        'pregnancy_weeks_required',
        'pre_delivery_weeks',
        'post_delivery_weeks',
        'requires_medical_certificate',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'is_active' => 'boolean',
        'is_pregnancy_related' => 'boolean',
        'requires_medical_certificate' => 'boolean',
        'default_allocation' => 'decimal:2',
    ];

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', "%{$search}%")
            ->orWhere('code', 'like', "%{$search}%");
    }
}
