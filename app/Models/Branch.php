<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'address_line1',
        'address_line2',
        'city',
        'postal_code',
        'zone_id',
        'region_id',
        'province_id',
        'phone_primary',
        'phone_secondary',
        'email',
        'fax',
        'opening_date',
        'branch_type',
        'latitude',
        'longitude',
        'is_active',
        'is_head_office'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_head_office' => 'boolean',
        'opening_date' => 'date',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    public function zonal(): BelongsTo
    {
        return $this->belongsTo(Zonal::class, 'zone_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', "%{$search}%")
            ->orWhere('code', 'LIKE', "%{$search}%")
            ->orWhere('city', 'LIKE', "%{$search}%");
    }
}
