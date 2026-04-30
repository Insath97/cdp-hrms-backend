<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\FingerprintSDKService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FingerprintWebhookController extends Controller
{
    protected $fingerprintService;
    
    public function __construct(FingerprintSDKService $fingerprintService)
    {
        $this->fingerprintService = $fingerprintService;
    }
    
    /**
     * Handle fingerprint device webhook (real-time attendance)
     */
    public function handleWebhook(Request $request)
    {
        // Verify webhook signature if configured
        $signature = $request->header('X-Signature');
        $payload = $request->all();
        
        if (config('fingerprint.webhook.verify_signature', false)) {
            $expectedSignature = hash_hmac(
                'sha256',
                json_encode($payload),
                config('fingerprint.webhook.secret')
            );
            
            if ($signature !== $expectedSignature) {
                Log::warning('Invalid webhook signature', [
                    'signature' => $signature,
                    'expected' => $expectedSignature
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid signature'
                ], 401);
            }
        }
        
        $result = $this->fingerprintService->processWebhook($payload);
        
        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
            'data' => $result['data'] ?? null
        ], $result['success'] ? 200 : 400);
    }
}