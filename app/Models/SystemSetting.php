<?php
// app/Models/SystemSetting.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = [
        'group', 'key', 'value', 'type', 'description', 'is_editable'
    ];

    protected $casts = [
        'is_editable' => 'boolean',
    ];

    protected static function booted()
    {
        static::saved(function () {
            Cache::forget('system_settings');
            Cache::forget('system_settings_group_attendance');
        });

        static::deleted(function () {
            Cache::forget('system_settings');
            Cache::forget('system_settings_group_attendance');
        });
    }

    /**
     * Get setting value with proper type casting
     */
    public function getTypedValueAttribute()
    {
        return match($this->type) {
            'integer' => (int) $this->value,
            'boolean' => (bool) $this->value,
            'decimal' => (float) $this->value,
            'json' => json_decode($this->value, true),
            default => (string) $this->value,
        };
    }

    /**
     * Set setting value with proper type handling
     */
    public function setTypedValueAttribute($value)
    {
        $this->value = match($this->type) {
            'json' => json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * Get a setting value by key
     */
    // app/Models/SystemSetting.php

public static function get($key, $default = null)
{
    $setting = self::where('key', $key)->first();

    if (!$setting) {
        return $default;
    }

    return self::castValue($setting->type, $setting->value);
}

protected static function castValue($type, $value)
{
    switch ($type) {
        case 'integer':
            return (int) $value;
        case 'boolean':
            return (bool) $value;
        case 'decimal':
            return (float) $value;
        default:
            return $value;
    }
}

    /**
     * Set a setting value
     */
    public static function set($key, $value, $group = 'general', $type = 'string', $description = null)
    {
        $setting = self::updateOrCreate(
            ['key' => $key],
            [
                'group' => $group,
                'value' => $value,
                'type' => $type,
                'description' => $description
            ]
        );

        Cache::forget('system_settings');

        return $setting;
    }

    /**
     * Get all settings by group
     */
    public static function getGroup($group)
    {
        $cache = Cache::remember("system_settings_group_{$group}", 3600, function () use ($group) {
            return self::where('group', $group)->get()->keyBy('key');
        });

        return $cache;
    }
}
