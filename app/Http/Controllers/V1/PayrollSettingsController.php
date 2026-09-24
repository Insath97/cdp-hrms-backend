<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\PayrollSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayrollSettingsController extends Controller
{
    public function index()
    {
        try {
            $settings = PayrollSetting::orderBy('id')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Payroll settings retrieved successfully',
                'data' => $settings,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve payroll settings', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve payroll settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request)
    {
        try {
            $request->validate([
                'settings' => 'required|array',
                'settings.*.key' => 'required|string|exists:payroll_settings,key',
                'settings.*.value' => 'required|string',
            ]);

            foreach ($request->settings as $item) {
                PayrollSetting::set($item['key'], $item['value']);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payroll settings updated successfully',
                'data' => PayrollSetting::allAsMap(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update payroll settings', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update payroll settings: ' . $e->getMessage(),
            ], 500);
        }
    }
}