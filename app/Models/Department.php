<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
        'head_id'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    public function head()
    {
        return $this->belongsTo(Employee::class, 'head_id');
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', "%{$search}%")->orWhere('code', 'LIKE', "%{$search}%");
    }
}
