<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FingerprintSDKService
{
    protected $deviceIp;
    protected $apiKey;
    protected $timeout;
    protected $devicePort;
    protected $officeLocation;
    
    public function __construct()
    {
        $this->deviceIp = config('fingerprint.device_ip', '192.168.1.100');
        $this->apiKey = config('fingerprint.api_key', '');
        $this->timeout = config('fingerprint.timeout', 10);
        $this->devicePort = config('fingerprint.device_port', 80);
        $this->officeLocation = [
            'latitude' => config('fingerprint.office_location.latitude', 0),
            'longitude' => config('fingerprint.office_location.longitude', 0),
            'radius' => config('fingerprint.office_location.radius', 100), // meters
        ];
    }
    
    /**
     * Test connection to fingerprint device
     */
    public function testConnection(): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->get("http://{$this->deviceIp}:{$this->devicePort}/api/health");
                
            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Device connected successfully',
                    'data' => $response->json()
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Device responded with error',
                'error' => $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('Fingerprint device connection failed', [
                'ip' => $this->deviceIp,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to connect to fingerprint device',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get all users/employees from fingerprint device
     */
    public function getUsers(): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                ])
                ->get("http://{$this->deviceIp}:{$this->devicePort}/api/users");
                
            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json('data', [])
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to fetch users',
                'error' => $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch fingerprint users', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get attendance logs from fingerprint device
     */
    public function getAttendanceLogs(?string $fromDate = null, ?string $toDate = null): array
    {
        try {
            $params = [];
            if ($fromDate) {
                $params['from_date'] = $fromDate;
            }
            if ($toDate) {
                $params['to_date'] = $toDate;
            }
            
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                ])
                ->get("http://{$this->deviceIp}:{$this->devicePort}/api/attendance/logs", $params);
                
            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json('data', [])
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to fetch attendance logs',
                'error' => $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch attendance logs', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to fetch attendance logs',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get real-time attendance today
     */
    public function getTodayAttendance(): array
    {
        $today = Carbon::today()->format('Y-m-d');
        return $this->getAttendanceLogs($today, $today);
    }
    
    /**
     * Register fingerprint for a user
     */
    public function registerFingerprint(int $userId, int $fingerprintNumber = 1): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                ])
                ->post("http://{$this->deviceIp}:{$this->devicePort}/api/users/{$userId}/fingerprint", [
                    'fingerprint_number' => $fingerprintNumber
                ]);
                
            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Fingerprint registration initiated',
                    'data' => $response->json()
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to register fingerprint',
                'error' => $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('Failed to register fingerprint', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to register fingerprint',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete fingerprint from device
     */
    public function deleteFingerprint(int $userId, int $fingerprintNumber = 1): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                ])
                ->delete("http://{$this->deviceIp}:{$this->devicePort}/api/users/{$userId}/fingerprint/{$fingerprintNumber}");
                
            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Fingerprint deleted successfully'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to delete fingerprint',
                'error' => $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('Failed to delete fingerprint', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to delete fingerprint',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Sync fingerprint user from HRMS to device
     */
    public function syncUserToDevice(array $userData): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                ])
                ->post("http://{$this->deviceIp}:{$this->devicePort}/api/users/sync", $userData);
                
            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'User synced successfully',
                    'data' => $response->json()
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to sync user',
                'error' => $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('Failed to sync user to device', [
                'user_data' => $userData,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to sync user',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get device info
     */
    public function getDeviceInfo(): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->get("http://{$this->deviceIp}:{$this->devicePort}/api/info");
                
            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to fetch device info',
                'error' => $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch device info', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to fetch device info',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Synchronize all attendance from device to HRMS with location support
     */
    public function syncAttendanceToHRMS(?Carbon $fromDate = null, ?Carbon $toDate = null): array
    {
        $fromDate = $fromDate ?? Carbon::today()->subDays(config('fingerprint.sync.default_days_back', 7));
        $toDate = $toDate ?? Carbon::today();
        
        $result = $this->getAttendanceLogs($fromDate->format('Y-m-d'), $toDate->format('Y-m-d'));
        
        if (!$result['success']) {
            return $result;
        }
        
        $syncedCount = 0;
        $updatedCount = 0;
        $errors = [];
        
        foreach ($result['data'] as $log) {
            try {
                // Find user by fingerprint user_id
                $user = User::where('fingerprint_user_id', $log['user_id'])->first();
                
                if (!$user) {
                    $errors[] = "User not found for fingerprint ID: {$log['user_id']}";
                    continue;
                }
                
                // Prepare attendance data
                $attendanceData = [
                    'user_id' => $user->id,
                    'employee_id' => $user->employee?->id,
                    'date' => $log['date'],
                ];
                
                // Handle clock in/out based on log type
                if ($log['type'] === 'clock_in' || !isset($log['type'])) {
                    $attendanceData['clock_in'] = $log['time'];
                    $attendanceData['in_latitude'] = $log['latitude'] ?? $this->officeLocation['latitude'];
                    $attendanceData['in_longitude'] = $log['longitude'] ?? $this->officeLocation['longitude'];
                }
                
                if ($log['type'] === 'clock_out') {
                    $attendanceData['clock_out'] = $log['time'];
                    $attendanceData['out_latitude'] = $log['latitude'] ?? $this->officeLocation['latitude'];
                    $attendanceData['out_longitude'] = $log['longitude'] ?? $this->officeLocation['longitude'];
                }
                
                // Use updateOrCreate to handle unique constraint
                $attendance = Attendance::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'date' => $log['date'],
                    ],
                    $attendanceData
                );
                
                // Calculate working hours if both clock_in and clock_out exist
                if ($attendance->clock_in && $attendance->clock_out) {
                    $workingHours = Attendance::calculateWorkingHours($attendance->clock_in, $attendance->clock_out);
                    $attendance->working_hours = $workingHours;
                    
                    // Determine status based on late policy
                    $isLate = Attendance::isLate(
                        $attendance->clock_in,
                        config('fingerprint.office_start_time', '09:00:00'),
                        config('fingerprint.grace_period_minutes', 15)
                    );
                    
                    $attendance->status = $isLate ? 'late' : 'present';
                    $attendance->save();
                }
                
                if ($attendance->wasRecentlyCreated) {
                    $syncedCount++;
                } else {
                    $updatedCount++;
                }
                
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->errorInfo[1] == 1062) { // Duplicate entry error
                    $errors[] = "Duplicate entry for user_id {$log['user_id']} on date {$log['date']}";
                } else {
                    $errors[] = "Database error for user_id {$log['user_id']}: {$e->getMessage()}";
                }
            } catch (\Exception $e) {
                $errors[] = "Error syncing log for user_id {$log['user_id']}: {$e->getMessage()}";
            }
        }
        
        return [
            'success' => true,
            'synced_count' => $syncedCount,
            'updated_count' => $updatedCount,
            'total_records' => count($result['data']),
            'errors' => $errors,
            'message' => "Synced {$syncedCount} new records, updated {$updatedCount} existing records"
        ];
    }
    
    /**
     * Process fingerprint webhook (real-time attendance from device)
     */
    public function processWebhook(array $payload): array
    {
        try {
            DB::beginTransaction();
            
            $fingerprintUserId = $payload['user_id'];
            $timestamp = Carbon::parse($payload['timestamp']);
            $date = $timestamp->toDateString();
            $time = $timestamp->format('H:i:s');
            $type = $payload['type'] ?? 'clock_in'; // clock_in or clock_out
            $latitude = $payload['latitude'] ?? $this->officeLocation['latitude'];
            $longitude = $payload['longitude'] ?? $this->officeLocation['longitude'];
            
            // Find user
            $user = User::where('fingerprint_user_id', $fingerprintUserId)->first();
            
            if (!$user) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => "User not found for fingerprint ID: {$fingerprintUserId}"
                ];
            }
            
            // Find or create attendance record
            $attendance = Attendance::firstOrNew([
                'user_id' => $user->id,
                'date' => $date,
            ]);
            
            // Update based on type
            if ($type === 'clock_in') {
                // Check if already clocked in
                if ($attendance->clock_in) {
                    DB::rollBack();
                    return [
                        'success' => false,
                        'message' => 'User already clocked in for today'
                    ];
                }
                
                $attendance->clock_in = $time;
                $attendance->in_latitude = $latitude;
                $attendance->in_longitude = $longitude;
                $attendance->employee_id = $user->employee?->id;
                $attendance->status = 'present';
                
            } elseif ($type === 'clock_out') {
                // Check if clocked in
                if (!$attendance->clock_in) {
                    DB::rollBack();
                    return [
                        'success' => false,
                        'message' => 'User has not clocked in yet'
                    ];
                }
                
                // Check if already clocked out
                if ($attendance->clock_out) {
                    DB::rollBack();
                    return [
                        'success' => false,
                        'message' => 'User already clocked out for today'
                    ];
                }
                
                $attendance->clock_out = $time;
                $attendance->out_latitude = $latitude;
                $attendance->out_longitude = $longitude;
                
                // Calculate working hours
                $attendance->working_hours = Attendance::calculateWorkingHours(
                    $attendance->clock_in, 
                    $attendance->clock_out
                );
                
                // Check if late (only for clock_in, but we can update status here)
                $isLate = Attendance::isLate(
                    $attendance->clock_in,
                    config('fingerprint.office_start_time', '09:00:00'),
                    config('fingerprint.grace_period_minutes', 15)
                );
                
                $attendance->status = $isLate ? 'late' : 'present';
            }
            
            $attendance->save();
            
            DB::commit();
            
            Log::info('Fingerprint webhook processed', [
                'user_id' => $user->id,
                'type' => $type,
                'timestamp' => $timestamp
            ]);
            
            return [
                'success' => true,
                'message' => ucfirst(str_replace('_', ' ', $type)) . ' recorded successfully',
                'data' => $attendance->fresh(['user', 'employee'])
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process fingerprint webhook', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to process attendance: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get attendance statistics from device
     */
    public function getDeviceStatistics(): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                ])
                ->get("http://{$this->deviceIp}:{$this->devicePort}/api/statistics");
                
            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to fetch device statistics',
                'error' => $response->body()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch device statistics',
                'error' => $e->getMessage()
            ];
        }
    }
}