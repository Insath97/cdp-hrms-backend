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
        'level',
        'description',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

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
