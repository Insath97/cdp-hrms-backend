<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PayrollSetting extends Model
{
    protected $table = 'payroll_settings';

    protected $fillable = ['key', 'label', 'value', 'type'];

    public $timestamps = true;

    private const CACHE_KEY = 'payroll_settings';

    /**
     * Get all settings as a key => value map (with type casting).
     */
    public static function allAsMap(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            $map = [];
            foreach (self::all() as $setting) {
                $value = $setting->value;
                if ($setting->type === 'boolean') {
                    $value = in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
                } elseif ($setting->type === 'number') {
                    $value = is_numeric($value) ? (float) $value : 0.0;
                }
                $map[$setting->key] = $value;
            }
            return $map;
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $map = self::allAsMap();
        return $map[$key] ?? $default;
    }

    public static function set(string $key, string $value): bool
    {
        $setting = self::where('key', $key)->first();
        if (! $setting) {
            return false;
        }
        $setting->value = $value;
        $setting->save();
        Cache::forget(self::CACHE_KEY);
        return true;
    }
}