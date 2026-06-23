<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAllowedLocation extends Model
{
    protected $fillable = [
        'user_id', 'geofence_id', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function geofence()
    {
        return $this->belongsTo(Geofence::class);
    }
}
