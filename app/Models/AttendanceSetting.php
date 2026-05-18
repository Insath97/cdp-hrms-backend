<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key
     */
    public static function getSetting($key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    /**
     * Get a setting as integer
     */
    public static function getIntSetting($key, $default = 0)
    {
        return (int) self::getSetting($key, $default);
    }
}
