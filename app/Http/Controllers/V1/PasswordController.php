<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\PasswordChangeRequest;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PasswordController extends Controller
{
    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    public function requestChange(Request $request)
    {
        try {
            $user = auth('api')->user();

            if ($user->user_type !== 'staff') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This feature is only available for staff users.',
                ], 403);
            }

            $user->load('employee');

            if (! $user->employee || empty($user->employee->phone_primary)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Primary phone number not found. Cannot send OTP.',
                ], 400);
            }

            // Check if there's already a pending request
            $existingPending = PasswordChangeRequest::where('user_id', $user->id)
                ->where('status', 'pending')
                ->first();

            if ($existingPending) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You already have a pending password change request.',
                ], 400);
            }

            if (is_null($user->password_changed_at)) {
                // First time changing password
                $otp = (string) rand(100000, 999999);
                $expiresAt = now()->addMinutes(15);

                $changeRequest = PasswordChangeRequest::create([
                    'user_id' => $user->id,
                    'employee_id' => $user->employee_id,
                    'otp' => $otp,
                    'expires_at' => $expiresAt,
                    'status' => 'approved',
                ]);

                // Send SMS
                $message = "Your OTP for password change is: {$otp}. Valid for 15 minutes.";
                $this->smsService->sendSms($user->employee->phone_primary, $message);

                return response()->json([
                    'status' => 'success',
                    'message' => 'OTP sent successfully to your primary phone number.',
                ], 200);

            } else {
                // Subsequent change: requires admin approval
                PasswordChangeRequest::create([
                    'user_id' => $user->id,
                    'employee_id' => $user->employee_id,
                    'status' => 'pending',
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Password change request sent to admin for approval.',
                ], 200);
            }

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process request',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function changeWithOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'otp' => 'required|numeric|digits:6',
                'password' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = auth('api')->user();

            $changeRequest = PasswordChangeRequest::where('user_id', $user->id)
                ->where('status', 'approved')
                ->where('otp', $request->otp)
                ->latest()
                ->first();

            if (! $changeRequest) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid OTP or no approved request found.',
                ], 400);
            }

            if ($changeRequest->expires_at && $changeRequest->expires_at->isPast()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'OTP has expired.',
                ], 400);
            }

            // Update user password
            $user->update([
                'password' => Hash::make($request->password),
                'password_changed_at' => now(),
            ]);

            // Mark request as verified
            $changeRequest->update(['status' => 'verified']);

            return response()->json([
                'status' => 'success',
                'message' => 'Password changed successfully.',
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to change password',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function forgotPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'username' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = \App\Models\User::where('username', $request->username)
                ->orWhere('email', $request->username)
                ->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.',
                ], 404);
            }

            if ($user->user_type !== 'staff') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This feature is only available for staff users.',
                ], 403);
            }

            $user->load('employee');

            if (!$user->employee || empty($user->employee->phone_primary)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Primary phone number not found. Cannot request password change.',
                ], 400);
            }

            // Check if there's already a pending request
            $existingPending = PasswordChangeRequest::where('user_id', $user->id)
                ->where('status', 'pending')
                ->first();

            if ($existingPending) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You already have a pending password change request.',
                ], 400);
            }

            // Forgot password always requires admin approval
            PasswordChangeRequest::create([
                'user_id' => $user->id,
                'employee_id' => $user->employee_id,
                'status' => 'pending',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Password change request sent to admin for approval.',
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process request',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function resetForgotPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'username' => 'required|string',
                'otp' => 'required|numeric|digits:6',
                'password' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = \App\Models\User::where('username', $request->username)
                ->orWhere('email', $request->username)
                ->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.',
                ], 404);
            }

            $changeRequest = PasswordChangeRequest::where('user_id', $user->id)
                ->where('status', 'approved')
                ->where('otp', $request->otp)
                ->latest()
                ->first();

            if (!$changeRequest) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid OTP or no approved request found.',
                ], 400);
            }

            if ($changeRequest->expires_at && $changeRequest->expires_at->isPast()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'OTP has expired.',
                ], 400);
            }

            // Update user password
            $user->update([
                'password' => Hash::make($request->password),
                'password_changed_at' => now(),
            ]);

            // Mark request as verified
            $changeRequest->update(['status' => 'verified']);

            return response()->json([
                'status' => 'success',
                'message' => 'Password changed successfully.',
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to change password',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
