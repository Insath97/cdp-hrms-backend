<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Letter extends Model
{
    use HasFactory;

    protected $fillable = [
        'ref_number',
        'title',
        'letter_type',
        'employee_name',
        'nic_number',
        'address_line1',
        'address_line2',
        'city',
        'department_id',
        'designation_id',
        'branch_id',
        'start_date',
        'end_date',
        'date',
        'signed_by_name',
        'signed_by_designation',
    ];

    // Relationships
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('employee_name', 'like', "%{$search}%")->orWhere('employee_name', 'LIKE', "%{$search}%");
    }
}
