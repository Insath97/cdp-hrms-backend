<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Generate the next employee code in the format RT00000001
     */
    public static function generateNextEmployeeCode(): string
    {
        $latestEmployee = self::orderBy('id', 'desc')->first();

        if (! $latestEmployee || ! $latestEmployee->employee_code) {
            return 'RT00000001';
        }

        $latestCode = $latestEmployee->employee_code;
        $numericPart = preg_replace('/[^0-9]/', '', $latestCode);
        $nextNumber = (int) $numericPart + 1;

        return 'RT'.str_pad($nextNumber, 8, '0', STR_PAD_LEFT);
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
        'extended_until',
        'extension_reason',
        'is_active',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'extended_until' => 'date:Y-m-d',
        'left_at' => 'date:Y-m-d',
        'permanent_at' => 'date:Y-m-d',
        'basic_salary' => 'decimal:2',
        'is_active' => 'boolean',
        'have_whatsapp' => 'boolean',
    ];

    public function getJoinedAtAttribute($value)
    {
        return $value ? date('Y-m-d', strtotime($value)) : null;
    }

    // protected function serializeDate(DateTimeInterface $date)
    // {
    //     return $date->format('Y-m-d');
    // }

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

    /**
     * Check if the employee is on leave on a specific date.
     */
    public function isOnLeave($date = null)
    {
        $date = $date ?: now()->toDateString();

        return \App\Models\Leave::where('employee_id', $this->id)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->exists();
    }

    /**
     * Get all subordinates IDs recursively where intermediate managers are on leave.
     * This is useful for finding whose leaves the current manager is responsible for.
     */
    public function getResponsibleSubordinateIds($date = null)
    {
        $date = $date ?: now()->toDateString();
        $responsibleIds = [];

        foreach ($this->subordinates as $subordinate) {
            $responsibleIds[] = $subordinate->id;

            // If the subordinate is a manager themselves AND is on leave,
            // then the current manager is also responsible for their subordinates.
            if ($subordinate->subordinates()->exists() && $subordinate->isOnLeave($date)) {
                $responsibleIds = array_merge($responsibleIds, $subordinate->getResponsibleSubordinateIds($date));
            }
        }

        return array_unique($responsibleIds);
    }
}
