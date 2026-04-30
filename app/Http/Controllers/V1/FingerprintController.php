<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\FingerprintSDKService;
use App\Models\User;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class FingerprintController extends Controller
{
    protected $fingerprintService;
    
    public function __construct(FingerprintSDKService $fingerprintService)
    {
        $this->fingerprintService = $fingerprintService;
    }
    
    /**
     * Test connection to fingerprint device
     */
    public function testConnection()
    {
        $result = $this->fingerprintService->testConnection();
        
        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
            'error' => $result['error'] ?? null
        ], $result['success'] ? 200 : 500);
    }
    
    /**
     * Get device information
     */
    public function getDeviceInfo()
    {
        $result = $this->fingerprintService->getDeviceInfo();
        
        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'] ?? 'Device info retrieved',
            'data' => $result['data'] ?? null
        ], $result['success'] ? 200 : 500);
    }
    
    /**
     * Get all users from fingerprint device
     */
    public function getDeviceUsers()
    {
        $result = $this->fingerprintService->getUsers();
        
        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'] ?? 'Users retrieved',
            'data' => $result['data'] ?? []
        ], $result['success'] ? 200 : 500);
    }
    
    /**
     * Get attendance logs from fingerprint device
     */
    public function getAttendanceLogs(Request $request)
    {
        $fromDate = $request->get('from_date');
        $toDate = $request->get('to_date');
        
        $result = $this->fingerprintService->getAttendanceLogs($fromDate, $toDate);
        
        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'] ?? 'Attendance logs retrieved',
            'data' => $result['data'] ?? []
        ], $result['success'] ? 200 : 500);
    }
    
    /**
     * Get today's attendance from device
     */
    public function getTodayAttendance()
    {
        $result = $this->fingerprintService->getTodayAttendance();
        
        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'] ?? 'Today\'s attendance retrieved',
            'data' => $result['data'] ?? []
        ], $result['success'] ? 200 : 500);
    }
    
    /**
     * Sync user to fingerprint device
     */
    public function syncUserToDevice(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'fingerprint_user_id' => 'required|integer',
            'name' => 'required|string',
            'employee_code' => 'nullable|string',
        ]);
        
        $user = User::find($validated['user_id']);
        
        $userData = [
            'user_id' => $validated['fingerprint_user_id'],
            'name' => $validated['name'],
            'employee_code' => $validated['employee_code'] ?? $user->employee?->employee_code,
            'department' => $user->employee?->department?->name,
        ];
        
        $result = $this->fingerprintService->syncUserToDevice($userData);
        
        if ($result['success']) {
            $user->fingerprint_user_id = $validated['fingerprint_user_id'];
            $user->save();
        }
        
        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
            'data' => $result['data'] ?? null
        ], $result['success'] ? 200 : 500);
    }
    
    /**
     * Register fingerprint for user
     */
    public function registerFingerprint(Request $request)
    {
        $validated = $request->validate([
            'fingerprint_user_id' => 'required|integer',
            'fingerprint_number' => 'nullable|integer|min:1|max:10',
        ]);
        
        $result = $this->fingerprintService->registerFingerprint(
            $validated['fingerprint_user_id'],
            $validated['fingerprint_number'] ?? 1
        );
        
        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
            'data' => $result['data'] ?? null
        ], $result['success'] ? 200 : 500);
    }
    
    /**
     * Delete fingerprint from device
     */
    public function deleteFingerprint(Request $request)
    {
        $validated = $request->validate([
            'fingerprint_user_id' => 'required|integer',
            'fingerprint_number' => 'nullable|integer|min:1|max:10',
        ]);
        
        $result = $this->fingerprintService->deleteFingerprint(
            $validated['fingerprint_user_id'],
            $validated['fingerprint_number'] ?? 1
        );
        
        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message']
        ], $result['success'] ? 200 : 500);
    }
    
    /**
     * Sync attendance from device to HRMS
     */
    public function syncAttendanceToHRMS(Request $request)
    {
        $fromDate = $request->get('from_date') 
            ? Carbon::parse($request->get('from_date')) 
            : Carbon::today()->subDays(config('fingerprint.sync.default_days_back', 7));
            
        $toDate = $request->get('to_date') 
            ? Carbon::parse($request->get('to_date')) 
            : Carbon::today();
        
        $result = $this->fingerprintService->syncAttendanceToHRMS($fromDate, $toDate);
        
        Log::info('Attendance sync completed', [
            'user_id' => Auth::id(),
            'result' => $result
        ]);
        
        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
            'data' => [
                'synced_count' => $result['synced_count'] ?? 0,
                'updated_count' => $result['updated_count'] ?? 0,
                'total_records' => $result['total_records'] ?? 0,
                'errors' => $result['errors'] ?? []
            ]
        ], $result['success'] ? 200 : 500);
    }
    
    /**
     * Get device statistics
     */
    public function getDeviceStatistics()
    {
        $result = $this->fingerprintService->getDeviceStatistics();
        
        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'] ?? 'Statistics retrieved',
            'data' => $result['data'] ?? null
        ], $result['success'] ? 200 : 500);
    }
    
    /**
     * Manual clock in/out via fingerprint (alternative to device)
     */
    public function manualAttendance(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'type' => 'required|in:clock_in,clock_out',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);
        
        $user = User::find($validated['user_id']);
        $today = Carbon::today()->format('Y-m-d');
        $now = Carbon::now();
        
        $attendance = Attendance::firstOrNew([
            'user_id' => $user->id,
            'date' => $today,
        ]);
        
        if ($validated['type'] === 'clock_in') {
            if ($attendance->clock_in) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User already clocked in today'
                ], 400);
            }
            
            $attendance->clock_in = $now->format('H:i:s');
            $attendance->in_latitude = $validated['latitude'] ?? null;
            $attendance->in_longitude = $validated['longitude'] ?? null;
            $attendance->employee_id = $user->employee?->id;
            $attendance->status = 'present';
            
        } else { // clock_out
            if (!$attendance->clock_in) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User has not clocked in yet'
                ], 400);
            }
            
            if ($attendance->clock_out) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User already clocked out today'
                ], 400);
            }
            
            $attendance->clock_out = $now->format('H:i:s');
            $attendance->out_latitude = $validated['latitude'] ?? null;
            $attendance->out_longitude = $validated['longitude'] ?? null;
            
            // Calculate working hours
            $attendance->working_hours = Attendance::calculateWorkingHours(
                $attendance->clock_in,
                $attendance->clock_out
            );
            
            // Check if late
            $isLate = Attendance::isLate(
                $attendance->clock_in,
                config('fingerprint.office_start_time', '09:00:00'),
                config('fingerprint.grace_period_minutes', 15)
            );
            
            $attendance->status = $isLate ? 'late' : 'present';
        }
        
        $attendance->save();
        
        return response()->json([
            'status' => 'success',
            'message' => ucfirst(str_replace('_', ' ', $validated['type'])) . ' recorded successfully',
            'data' => $attendance->load('user', 'employee')
        ], 200);
    }
    
    /**
     * Get attendance comparison between HRMS and device
     */
    public function compareAttendance(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
        ]);
        
        $date = $validated['date'];
        
        // Get HRMS attendance
        $hrmsAttendance = Attendance::with('user')
            ->whereDate('date', $date)
            ->get()
            ->keyBy('user_id');
        
        // Get device attendance
        $deviceResult = $this->fingerprintService->getAttendanceLogs($date, $date);
        
        $comparison = [
            'date' => $date,
            'hrms_count' => $hrmsAttendance->count(),
            'device_count' => $deviceResult['success'] ? count($deviceResult['data']) : 0,
            'matches' => [],
            'hrms_only' => [],
            'device_only' => [],
        ];
        
        if ($deviceResult['success']) {
            $deviceAttendance = collect($deviceResult['data'])->groupBy('user_id');
            
            foreach ($hrmsAttendance as $userId => $attendance) {
                if ($deviceAttendance->has($attendance->user->fingerprint_user_id)) {
                    $comparison['matches'][] = [
                        'user_id' => $userId,
                        'user_name' => $attendance->user->name,
                        'hrms_clock_in' => $attendance->clock_in,
                        'hrms_clock_out' => $attendance->clock_out,
                    ];
                } else {
                    $comparison['hrms_only'][] = [
                        'user_id' => $userId,
                        'user_name' => $attendance->user->name,
                    ];
                }
            }
            
            foreach ($deviceAttendance as $deviceUserId => $logs) {
                $user = User::where('fingerprint_user_id', $deviceUserId)->first();
                if ($user && !$hrmsAttendance->has($user->id)) {
                    $comparison['device_only'][] = [
                        'fingerprint_user_id' => $deviceUserId,
                        'user_name' => $user->name,
                        'logs' => $logs
                    ];
                }
            }
        }
        
        return response()->json([
            'status' => 'success',
            'data' => $comparison
        ], 200);
    }
}