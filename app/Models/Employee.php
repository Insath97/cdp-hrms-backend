<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Generate the next employee code in the format RT00000001
     */
    public static function generateNextEmployeeCode(): string
    {
        $latestEmployee = self::orderBy('id', 'desc')->first();

        if (!$latestEmployee || !$latestEmployee->employee_code) {
            return 'RT00000001';
        }

        $latestCode = $latestEmployee->employee_code;
        $numericPart = preg_replace('/[^0-9]/', '', $latestCode);
        $nextNumber = (int) $numericPart + 1;

        return 'RT' . str_pad($nextNumber, 8, '0', STR_PAD_LEFT);
    }

    protected $fillable = [
        'f_name',
        'l_name',
        'full_name',
        'name_with_initials',
        'employee_code',
        'profile_image',
        'reporting_manager_id',
        'province_id',
        'region_id',
        'zonal_id',
        'branch_id',
        'department_id',
        'designation_id',
        'employee_type',
        'id_type',
        'id_number',
        'date_of_birth',
        'email',
        'phone',
        'address_line_1',
        'city',
        'state',
        'country',
        'postal_code',
        'phone_primary',
        'phone_secondary',
        'have_whatsapp',
        'whatsapp_number',
        'start_date',
        'end_date',
        'joined_at',
        'left_at',
        'termination_reason',
        'permanent_at',
        'employment_status',
        'basic_salary',
        'bank_name',
        'bank_branch',
        'account_number',
        'description',
        'is_active'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'permanent_at' => 'datetime',
        'basic_salary' => 'decimal:2',
        'is_active' => 'boolean',
        'have_whatsapp' => 'boolean'
    ];

    // Relationships
    public function reportingManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'reporting_manager_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function zonal(): BelongsTo
    {
        return $this->belongsTo(Zonal::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('employment_status', 'active');
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('full_name', 'like', "%{$search}%")
            ->orWhere('employee_code', 'like', "%{$search}%")
            ->orWhere('id_number', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%");
    }
}
