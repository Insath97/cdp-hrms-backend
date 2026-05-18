<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'name',
        'type',
        'is_company_holiday',
        'year',
        'description',
    ];

    protected $casts = [
        'date' => 'date',
        'is_company_holiday' => 'boolean',
    ];

    /**
     * Scope a query to only include holidays for a specific year.
     */
    public function scopeForYear($query, $year)
    {
        return $query->where('year', $year);
    }

    /**
     * Check if a specific date is a holiday.
     */
    public static function isHoliday($date)
    {
        $carbonDate = ($date instanceof \Carbon\Carbon) ? $date : \Carbon\Carbon::parse($date);
        
        // Check for weekends (Saturday = 6, Sunday = 0)
        if ($carbonDate->isWeekend()) {
            return true;
        }

        return self::where('date', $carbonDate->toDateString())->where('is_company_holiday', true)->exists();
    }
}
