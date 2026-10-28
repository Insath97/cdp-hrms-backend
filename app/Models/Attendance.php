<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Attendance extends Model
{
    use HasFactory, SoftDeletes;

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
        'in_geofence_name',
        'out_geofence_name',
        'in_ipAddress',
        'out_ipAddress',
        'working_hours',
        'status',
        'late_minutes',
        'exceeds_grace_period',
        'converted_at',
        'converted_leave_type',
        'grace_period_applied',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',  // Changed from datetime to date
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

        try {
            // Helper to reliably extract H:i:s regardless of whether input is Carbon, datetime string, or time string
            $extractTime = function ($val): ?string {
                if (!$val) return null;
                if ($val instanceof Carbon || $val instanceof \DateTimeInterface) {
                    return $val->format('H:i:s');
                }
                $str = trim((string) $val);
                if (strpos($str, ' ') !== false) {
                    $parts = explode(' ', $str);
                    return end($parts);
                }
                if (strpos($str, 'T') !== false) {
                    $parts = explode('T', $str);
                    return end($parts);
                }
                return $str;
            };

            $timeIn = $extractTime($clock_in);
            $timeOut = $extractTime($clock_out);

            if (!$timeIn || !$timeOut) {
                return null;
            }

            // Always anchor to the exact same reference date to prevent multi-day jumps or negative offsets
            $start = Carbon::parse('2000-01-01 ' . $timeIn);
            $end = Carbon::parse('2000-01-01 ' . $timeOut);

            // If end time is earlier than start time, assume it's an overnight shift (e.g. 22:00 to 06:00)
            if ($end < $start) {
                $end->addDay();
            }

            $diffMinutes = $start->diffInMinutes($end);
            $hours = $diffMinutes / 60;
            return round(max(0, $hours), 2);
        } catch (\Exception $e) {
            \Log::error('Error calculating working hours', [
                'clock_in' => $clock_in,
                'clock_out' => $clock_out,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Check if attendance is late
     */
    public static function isLate($clock_in, $officeStartTime = '09:00:00', $gracePeriodMinutes = 15): bool
    {
        if (!$clock_in) {
            return false;
        }

        try {
            $clockInTime = Carbon::createFromFormat('H:i:s', $clock_in);
            $officeStart = Carbon::createFromFormat('H:i:s', $officeStartTime);
            $gracePeriod = $officeStart->copy()->addMinutes($gracePeriodMinutes);

            return $clockInTime > $gracePeriod;
        } catch (\Exception $e) {
            \Log::error('Error checking late status', [
                'clock_in' => $clock_in,
                'error' => $e->getMessage()
            ]);
            return false;
        }
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
        return $query->whereDate('date', $date);
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
