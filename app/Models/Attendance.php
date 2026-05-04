<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'employee_id',
        'date',
        'clock_in',
        'clock_out',
        'in_latitude',
        'in_longitude',
        'out_latitude',
        'out_longitude',
        'working_hours',
        'status',
    ];

    protected $casts = [
        'date' => 'datetime:Y-m-d',  // Format   
        'clock_in' => 'datetime:H:i:s',
        'clock_out' => 'datetime:H:i:s',
        'in_latitude' => 'decimal:8',
        'in_longitude' => 'decimal:8',
        'out_latitude' => 'decimal:8',
        'out_longitude' => 'decimal:8',
        'working_hours' => 'decimal:2',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Calculate working hours from clock_in and clock_out
     */
    public static function calculateWorkingHours($clock_in, $clock_out): ?float
    {
        if (!$clock_in || !$clock_out) {
            return null;
        }

        $start = \Carbon\Carbon::createFromFormat('H:i:s', $clock_in);
        $end = \Carbon\Carbon::createFromFormat('H:i:s', $clock_out);

        // If end time is earlier than start time, assume it's the next day
        if ($end < $start) {
            $end->addDay();
        }

        $hours = $start->diffInMinutes($end) / 60;
        return round($hours, 2);
    }

    /**
     * Check if attendance is late
     */
    public static function isLate($clock_in, $officeStartTime = '09:00:00', $gracePeriodMinutes = 15): bool
    {
        if (!$clock_in) {
            return false;
        }
        
        $clockInTime = \Carbon\Carbon::createFromFormat('H:i:s', $clock_in);
        $officeStart = \Carbon\Carbon::createFromFormat('H:i:s', $officeStartTime);
        $gracePeriod = $officeStart->copy()->addMinutes($gracePeriodMinutes);
        
        return $clockInTime > $gracePeriod;
    }

    // Scopes
    public function scopeSearch($query, $search)
    {
        return $query->whereHas('user', function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        })->orWhereHas('employee', function ($q) use ($search) {
            $q->where('full_name', 'like', "%{$search}%")
                ->orWhere('employee_code', 'like', "%{$search}%");
        });
    }

    public function scopeByDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeByUser($query, $user_id)
    {
        return $query->where('user_id', $user_id);
    }

    public function scopeByEmployee($query, $employee_id)
    {
        return $query->where('employee_id', $employee_id);
    }

    public function scopeDateRange($query, $from_date, $to_date)
    {
        return $query->whereBetween('date', [$from_date, $to_date]);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('date', today());
    }

    public function scopeActive($query)
    {
        return $query->whereNull('clock_out');
    }
}