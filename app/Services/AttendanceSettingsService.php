<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AttendanceSettingsService
{
    protected $settings = [];
    protected $cacheKey = 'attendance_settings';

    public function __construct()
    {
        $this->loadSettings();
    }

    /**
     * Load all attendance settings
     */
    protected function loadSettings()
    {
        $this->settings = Cache::remember($this->cacheKey, 3600, function () {
            $settings = SystemSetting::getGroup('attendance');
            $defaults = $this->getDefaultSettings();

            foreach ($defaults as $key => $default) {
                if (!isset($settings[$key])) {
                    $this->createDefaultSetting($key, $default);
                }
            }

            $loaded = [];
            foreach ($defaults as $key => $default) {
                $setting = SystemSetting::get($key, $default['default']);
                $loaded[$key] = $setting;
            }

            return $loaded;
        });
    }

    /**
     * Get default attendance settings
     */
    protected function getDefaultSettings()
    {
        return [
            // Grace Period Settings
            'grace_period_minutes' => [
                'default' => 5,
                'type' => 'integer',
                'description' => 'Grace period in minutes for late arrivals',
                'group' => 'grace_period'
            ],

            // Consecutive Late Rules
            'short_leave_consecutive_days' => [
                'default' => 2,
                'type' => 'integer',
                'description' => 'Consecutive late days to convert to short leave',
                'group' => 'consecutive_rules'
            ],
            'half_day_consecutive_days' => [
                'default' => 4,
                'type' => 'integer',
                'description' => 'Consecutive late days to convert to half day',
                'group' => 'consecutive_rules'
            ],
            'absent_consecutive_days' => [
                'default' => 8,
                'type' => 'integer',
                'description' => 'Consecutive late days to convert to absent',
                'group' => 'consecutive_rules'
            ],

            // Office Timing Settings
            'office_start_time' => [
                'default' => '09:00:00',
                'type' => 'string',
                'description' => 'Office start time (24-hour format)',
                'group' => 'office_timing'
            ],
            'office_end_time' => [
                'default' => '18:00:00',
                'type' => 'string',
                'description' => 'Office end time (24-hour format)',
                'group' => 'office_timing'
            ],
            'break_time_minutes' => [
                'default' => 60,
                'type' => 'integer',
                'description' => 'Break time in minutes',
                'group' => 'office_timing'
            ],
            'working_hours_per_day' => [
                'default' => 8,
                'type' => 'decimal',
                'description' => 'Expected working hours per day',
                'group' => 'office_timing'
            ],

            // Auto Conversion Settings
            'auto_convert_late_to_leave' => [
                'default' => true,
                'type' => 'boolean',
                'description' => 'Automatically convert consecutive late attendance to leave',
                'group' => 'auto_conversion'
            ],
            'notify_on_auto_conversion' => [
                'default' => true,
                'type' => 'boolean',
                'description' => 'Send notification when late attendance is auto-converted',
                'group' => 'auto_conversion'
            ],

            // Leave Type Mappings
            'short_leave_type_id' => [
                'default' => null,
                'type' => 'integer',
                'description' => 'Leave type for short leave (2-3 consecutive lates)',
                'group' => 'leave_mappings'
            ],
            'half_day_leave_type_id' => [
                'default' => null,
                'type' => 'integer',
                'description' => 'Leave type for half day (4-7 consecutive lates)',
                'group' => 'leave_mappings'
            ],
            'absent_leave_type_id' => [
                'default' => null,
                'type' => 'integer',
                'description' => 'Leave type for absent (8+ consecutive lates)',
                'group' => 'leave_mappings'
            ],
        ];
    }

    /**
     * Create a default setting if it doesn't exist
     */
    protected function createDefaultSetting($key, $config)
    {
        SystemSetting::create([
            'group' => 'attendance',
            'key' => $key,
            'value' => $config['default'],
            'type' => $config['type'],
            'description' => $config['description'],
            'is_editable' => true,
        ]);
    }

    /**
     * Get a specific setting
     */
    public function get($key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Get all settings
     */
    public function getAll()
    {
        return $this->settings;
    }

    /**
     * Get settings by group
     */
    public function getByGroup($group)
    {
        $defaults = $this->getDefaultSettings();
        $groupSettings = [];

        foreach ($defaults as $key => $config) {
            if (isset($config['group']) && $config['group'] === $group) {
                $groupSettings[$key] = $this->get($key);
            }
        }

        return $groupSettings;
    }

    /**
     * Update a setting
     */
    public function update($key, $value)
    {
        $setting = SystemSetting::where('group', 'attendance')
            ->where('key', $key)
            ->first();

        if (!$setting) {
            throw new \Exception("Setting {$key} not found");
        }

        // Validate based on type
        $this->validateSetting($setting->type, $value);

        // Convert value based on type
        $typedValue = $this->castValue($setting->type, $value);

        $setting->value = $typedValue;
        $setting->save();

        // Update cache
        $this->settings[$key] = $typedValue;
        Cache::forget($this->cacheKey);

        Log::info('Attendance setting updated', [
            'key' => $key,
            'old_value' => $this->settings[$key] ?? null,
            'new_value' => $typedValue
        ]);

        return $typedValue;
    }

    /**
     * Batch update multiple settings
     */
    public function batchUpdate(array $settings)
    {
        $updated = [];
        foreach ($settings as $key => $value) {
            try {
                $updated[$key] = $this->update($key, $value);
            } catch (\Exception $e) {
                Log::error('Failed to update setting', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        return $updated;
    }

    /**
     * Validate setting value based on type
     */
    protected function validateSetting($type, $value)
    {
        switch ($type) {
            case 'integer':
                if (!is_numeric($value) || (int)$value != $value) {
                    throw new \Exception("Value must be an integer");
                }
                if ($value < 0) {
                    throw new \Exception("Value cannot be negative");
                }
                break;
            case 'decimal':
                if (!is_numeric($value)) {
                    throw new \Exception("Value must be a number");
                }
                if ($value < 0) {
                    throw new \Exception("Value cannot be negative");
                }
                break;
            case 'boolean':
                if (!in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'])) {
                    throw new \Exception("Value must be a boolean");
                }
                break;
            case 'string':
                if (!is_string($value)) {
                    throw new \Exception("Value must be a string");
                }
                break;
        }

        return true;
    }

    /**
     * Cast value to appropriate type
     */
    protected function castValue($type, $value)
    {
        return match($type) {
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }

    /**
     * Get grace period in minutes
     */
    public function getGracePeriod()
    {
        return $this->get('grace_period_minutes', 5);
    }

    /**
     * Get short leave consecutive days threshold
     */
    public function getShortLeaveThreshold()
    {
        return $this->get('short_leave_consecutive_days', 2);
    }

    /**
     * Get half day consecutive days threshold
     */
    public function getHalfDayThreshold()
    {
        return $this->get('half_day_consecutive_days', 4);
    }

    /**
     * Get absent consecutive days threshold
     */
    public function getAbsentThreshold()
    {
        return $this->get('absent_consecutive_days', 8);
    }

    /**
     * Get short leave type ID
     */
    public function getShortLeaveTypeId()
    {
        return $this->get('short_leave_type_id', null);
    }

    /**
     * Get half day leave type ID
     */
    public function getHalfDayLeaveTypeId()
    {
        return $this->get('half_day_leave_type_id', null);
    }

    /**
     * Get absent leave type ID
     */
    public function getAbsentLeaveTypeId()
    {
        return $this->get('absent_leave_type_id', null);
    }

    /**
     * Check if auto conversion is enabled
     */
    public function isAutoConversionEnabled()
    {
        return $this->get('auto_convert_late_to_leave', true);
    }

    /**
     * Get consecutive rule for a specific count
     */
    public function getRuleForConsecutiveDays($consecutiveDays)
    {
        $shortLeaveThreshold = $this->getShortLeaveThreshold();
        $halfDayThreshold = $this->getHalfDayThreshold();
        $absentThreshold = $this->getAbsentThreshold();

        if ($consecutiveDays >= $absentThreshold) {
            return [
                'type' => 'absent',
                'threshold' => $absentThreshold,
                'leave_type_id' => $this->getAbsentLeaveTypeId(),
                'label' => 'Absent (No Pay)',
                'days_deduct' => 1
            ];
        } elseif ($consecutiveDays >= $halfDayThreshold) {
            return [
                'type' => 'half_day',
                'threshold' => $halfDayThreshold,
                'leave_type_id' => $this->getHalfDayLeaveTypeId(),
                'label' => 'Half Day',
                'days_deduct' => 0.5
            ];
        } elseif ($consecutiveDays >= $shortLeaveThreshold) {
            return [
                'type' => 'short_leave',
                'threshold' => $shortLeaveThreshold,
                'leave_type_id' => $this->getShortLeaveTypeId(),
                'label' => 'Short Leave',
                'days_deduct' => 0.25
            ];
        }

        return null;
    }

    /**
     * Reset all settings to defaults
     */
    public function resetToDefaults()
    {
        $defaults = $this->getDefaultSettings();
        $reset = [];

        foreach ($defaults as $key => $config) {
            $reset[$key] = $this->update($key, $config['default']);
        }

        Log::info('Attendance settings reset to defaults', [
            'user_id' => auth()->id() ?? 'system'
        ]);

        return $reset;
    }
}
