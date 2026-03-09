<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'f_name',
        'l_name',
        'full_name',
        'name_with_initials',
        'profile_image',
        'department_id',
        'id_type',
        'id_number',
        'date_of_birth',
        'email',
        'phone',
        'address_line_1',
        'address_line_2',
        'landmark',
        'city',
        'state',
        'country',
        'postal_code',
        'phone_primary',
        'phone_secondary',
        'have_whatsapp',
        'whatsapp_number',
        'joined_at',
        'left_at',
        'basic_salary',
        'is_active'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'basic_salary' => 'decimal:2',
        'is_active' => 'boolean',
        'have_whatsapp' => 'boolean'
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function leadsDepartment()
    {
        return $this->hasOne(Department::class, 'head_id');
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('full_name', 'like', "%{$search}%")
            ->orWhere('employee_id', 'like', "%{$search}%")
            ->orWhere('id_number', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%");
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
