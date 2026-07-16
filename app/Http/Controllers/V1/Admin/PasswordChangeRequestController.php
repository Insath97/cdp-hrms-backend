<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PasswordChangeRequest;
use App\Notifications\PasswordRequestStatusNotification;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PasswordChangeRequestController extends Controller
{
    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    public function index(Request $request)
    {
        try {
            $requests = PasswordChangeRequest::with(['user', 'employee'])
                // ->where('status', 'pending')
                ->latest()
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $requests,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch requests',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function approve(Request $request, $id)
    {
        try {
            $changeRequest = PasswordChangeRequest::with(['employee', 'user'])->findOrFail($id);

            if ($changeRequest->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only pending requests can be approved.',
                ], 400);
            }

            if (! $changeRequest->employee || empty($changeRequest->employee->phone_primary)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Primary phone number not found for this employee. Cannot send OTP.',
                ], 400);
            }

            $otp = (string) rand(100000, 999999);
            $expiresAt = now()->addMinutes(60);

            $changeRequest->update([
                'otp' => $otp,
                'expires_at' => $expiresAt,
                'status' => 'approved',
            ]);

            // Send SMS
            $message = "Your OTP for password change of HRIS is {$otp}. Valid for 60 minutes.";
            $smsSent = $this->smsService->sendSms($changeRequest->employee->phone_primary, $message);
            Log::info('Admin approved password request: OTP SMS sent', [
                'change_request_id' => $changeRequest->id,
                'employee_id' => $changeRequest->employee_id,
                'phone' => $changeRequest->employee->phone_primary,
                'sms_sent' => $smsSent,
            ]);

            // Notify the requesting user
            if ($changeRequest->user) {
                $changeRequest->user->notify(new PasswordRequestStatusNotification($changeRequest, 'approved'));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Request approved and OTP sent successfully.',
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve request',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function reject(Request $request, $id)
    {
        try {
            $changeRequest = PasswordChangeRequest::with('user')->findOrFail($id);

            if ($changeRequest->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only pending requests can be rejected.',
                ], 400);
            }

            $changeRequest->update([
                'status' => 'rejected',
            ]);

            // Notify the requesting user
            if ($changeRequest->user) {
                $changeRequest->user->notify(new PasswordRequestStatusNotification($changeRequest, 'rejected'));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Request rejected successfully.',
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject request',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
