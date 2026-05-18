<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use Illuminate\Http\Request;

class AttendanceSettingController extends Controller
{
    public function getAll()
    {
        $settings = AttendanceSetting::all()->pluck('value', 'key');
        
        // Add defaults if they don't exist
        $defaults = [
            'grace_period_minutes' => 5,
            'short_leave_consecutive_days' => 2,
            'half_day_consecutive_days' => 4,
            'absent_consecutive_days' => 8,
            'late_consecutive_days' => 3,
            'consider_holidays' => true,
            'consider_leaves' => true,
            'auto_convert_late_to_leave' => true,
            'office_start_time' => '08:30:00',
            'office_end_time' => '17:00:00',
        ];

        foreach ($defaults as $key => $val) {
            if (!isset($settings[$key])) {
                $settings[$key] = $val;
            }
        }
        
        // Cast string to ints where necessary for frontend
        if (isset($settings['grace_period_minutes'])) $settings['grace_period_minutes'] = (int) $settings['grace_period_minutes'];
        if (isset($settings['short_leave_consecutive_days'])) $settings['short_leave_consecutive_days'] = (int) $settings['short_leave_consecutive_days'];
        if (isset($settings['half_day_consecutive_days'])) $settings['half_day_consecutive_days'] = (int) $settings['half_day_consecutive_days'];
        if (isset($settings['absent_consecutive_days'])) $settings['absent_consecutive_days'] = (int) $settings['absent_consecutive_days'];
        if (isset($settings['short_leave_type_id'])) $settings['short_leave_type_id'] = (int) $settings['short_leave_type_id'];
        if (isset($settings['half_day_leave_type_id'])) $settings['half_day_leave_type_id'] = (int) $settings['half_day_leave_type_id'];
        if (isset($settings['absent_leave_type_id'])) $settings['absent_leave_type_id'] = (int) $settings['absent_leave_type_id'];
        if (isset($settings['late_consecutive_days'])) $settings['late_consecutive_days'] = (int) $settings['late_consecutive_days'];
        if (isset($settings['consider_holidays'])) $settings['consider_holidays'] = filter_var($settings['consider_holidays'], FILTER_VALIDATE_BOOLEAN);
        if (isset($settings['consider_leaves'])) $settings['consider_leaves'] = filter_var($settings['consider_leaves'], FILTER_VALIDATE_BOOLEAN);
        
        // Boolean casts
        if (isset($settings['auto_convert_late_to_leave'])) {
            $settings['auto_convert_late_to_leave'] = filter_var($settings['auto_convert_late_to_leave'], FILTER_VALIDATE_BOOLEAN);
        }

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    public function get($key)
    {
        $setting = AttendanceSetting::where('key', $key)->first();
        return response()->json([
            'status' => 'success',
            'data' => $setting ? $setting->value : null
        ]);
    }

    public function update(Request $request, $key)
    {
        $request->validate(['value' => 'required']);
        
        AttendanceSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $request->value]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Setting updated successfully'
        ]);
    }

    public function batchUpdate(Request $request)
    {
        $request->validate(['settings' => 'required|array']);

        foreach ($request->settings as $setting) {
            AttendanceSetting::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Settings updated successfully'
        ]);
    }
}
