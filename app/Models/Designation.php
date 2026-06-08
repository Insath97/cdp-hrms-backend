<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Designation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'department_id',
        'monthly_target',
        'basic_salary',
        'travel_reimbursement',
        'vehicle_rental',
        'performance_allowance',
        'incentive',
        'position_allowance',
        'total_package',
        'level',
        'description',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($designation) {
            $designation->total_package = 
                (int) ($designation->basic_salary ?? 0) +
                (int) ($designation->travel_reimbursement ?? 0) +
                (int) ($designation->vehicle_rental ?? 0) +
                (int) ($designation->performance_allowance ?? 0) +
                (int) ($designation->incentive ?? 0) +
                (int) ($designation->position_allowance ?? 0);
        });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', "%{$search}%")
            ->orWhere('code', 'LIKE', "%{$search}%");
    }
}
