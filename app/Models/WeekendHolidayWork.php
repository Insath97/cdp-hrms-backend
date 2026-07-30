<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeekendHolidayWork extends Model
{
    protected $fillable = [
        'user_id', 'employee_id', 'date', 'clock_in', 'clock_out', 'description',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'clock_in' => 'string',
        'clock_out' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
