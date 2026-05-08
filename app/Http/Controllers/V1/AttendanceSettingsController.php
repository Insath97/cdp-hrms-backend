<?php
// app/Http/Controllers/V1/AttendanceSettingsController.php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\AttendanceSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AttendanceSettingsController extends Controller implements HasMiddleware
{
    protected $settingsService;

    public function __construct(AttendanceSettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Attendance Settings View', only: ['index', 'show', 'metadata']),
            new Middleware('permission:Attendance Settings Update', only: ['update', 'batchUpdate']),
            new Middleware('permission:Attendance Settings Reset', only: ['reset']),
        ];
    }

    /**
     * Get all attendance settings
     */
    public function index()
    {
        try {
            $settings = $this->settingsService->getAll();

            Log::info('Attendance settings retrieved', [
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendance settings retrieved successfully',
                'data' => $settings
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve attendance settings', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve attendance settings',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific setting
     */
    public function show($key)
    {
        try {
            $value = $this->settingsService->get($key);

            if ($value === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Setting '{$key}' not found"
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Setting retrieved successfully',
                'data' => [
                    'key' => $key,
                    'value' => $value
                ]
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve setting', [
                'user_id' => Auth::id(),
                'key' => $key,
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve setting',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update a specific setting
     */
    public function update(Request $request, $key)
    {
        try {
            $request->validate([
                'value' => 'required'
            ]);

            $oldValue = $this->settingsService->get($key);
            $newValue = $this->settingsService->update($key, $request->value);

            Log::info('Attendance setting updated', [
                'user_id' => Auth::id(),
                'key' => $key,
                'old_value' => $oldValue,
                'new_value' => $newValue
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Setting updated successfully',
                'data' => [
                    'key' => $key,
                    'old_value' => $oldValue,
                    'new_value' => $newValue
                ]
            ], 200);
        } catch (\Exception $th) {
            Log::error('Failed to update setting', [
                'user_id' => Auth::id(),
                'key' => $key,
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $th->getMessage()
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update setting',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Batch update multiple settings
     */
    public function batchUpdate(Request $request)
    {
        try {
            $request->validate([
                'settings' => 'required|array',
                'settings.*.key' => 'required|string',
                'settings.*.value' => 'required'
            ]);

            $settings = [];
            foreach ($request->settings as $setting) {
                $settings[$setting['key']] = $setting['value'];
            }

            $updated = $this->settingsService->batchUpdate($settings);

            Log::info('Batch attendance settings updated', [
                'user_id' => Auth::id(),
                'updated_keys' => array_keys($updated)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Settings updated successfully',
                'data' => $updated
            ], 200);
        } catch (\Exception $th) {
            Log::error('Failed to batch update settings', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $th->getMessage()
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update settings',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Reset all settings to defaults
     */
    public function reset()
    {
        try {
            $resetSettings = $this->settingsService->resetToDefaults();

            Log::info('Attendance settings reset to defaults', [
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Settings reset to defaults successfully',
                'data' => $resetSettings
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to reset settings', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reset settings',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get settings metadata (descriptions, types, default values)
     */
    public function metadata()
    {
        try {
            $settingsService = new \App\Services\AttendanceSettingsService();
            $reflection = new \ReflectionClass($settingsService);
            $method = $reflection->getMethod('getDefaultSettings');
            $method->setAccessible(true);
            $defaults = $method->invoke($settingsService);

            $metadata = [];
            foreach ($defaults as $key => $config) {
                $metadata[$key] = [
                    'type' => $config['type'],
                    'description' => $config['description'],
                    'default_value' => $config['default'],
                    'current_value' => $this->settingsService->get($key)
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Settings metadata retrieved successfully',
                'data' => $metadata
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve metadata',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
