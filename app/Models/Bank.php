<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    protected $fillable = [
        'name',
        'swift_code',
        'account_number_length',
        'account_number_format',
        'account_number_example',
        'is_commercial',
        'is_active',
    ];

    protected $casts = [
        'account_number_length' => 'integer',
        'is_commercial' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCommercial($query)
    {
        return $query->where('is_commercial', true);
    }

    public function scopeSearch($query, ?string $search)
    {
        if ($search === null || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('swift_code', 'like', "%{$search}%");
        });
    }
}
