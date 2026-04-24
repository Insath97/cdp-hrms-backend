<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'date',
        'clock_in',
        'clock_out',
        'working_hours',
        'status',
        'user_id',
    ];

    protected $casts = [
        // Don't cast date/time to avoid timezone conversions
        // They'll be returned as strings which is what we need
    ];

    // Relationships
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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

    public function scopeSearch($query, $search)
    {
        return $query->whereHas('employee', function ($q) use ($search) {
            $q->where('full_name', 'like', "%{$search}%")
                ->orWhere('employee_code', 'like', "%{$search}%");
        });
    }

    public function scopeByDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeByEmployee($query, $employee_id)
    {
        return $query->where('employee_id', $employee_id);
    }

    public function scopeDateRange($query, $from_date, $to_date)
    {
        return $query->whereBetween('date', [$from_date, $to_date]);
    }
}
