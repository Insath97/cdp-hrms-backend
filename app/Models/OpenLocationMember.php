<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenLocationMember extends Model
{
    use HasFactory;

    /**
     * Marker saved in in_geofence_name / out_geofence_name for open-location punches.
     */
    public const LABEL = 'Open Location';

    protected $fillable = [
        'user_id',
        'employee_id',
        'request_id',
        'approved_by',
        'approved_at',
        'is_active',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OpenLocationRequest::class, 'request_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
