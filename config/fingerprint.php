<?php
// config/fingerprint.php

return [
    'device_ip' => env('FINGERPRINT_DEVICE_IP', '192.168.1.100'),
    'device_port' => env('FINGERPRINT_DEVICE_PORT', 80),
    'api_key' => env('FINGERPRINT_API_KEY', ''),
    'timeout' => env('FINGERPRINT_TIMEOUT', 10),
    
    'office_location' => [
        'latitude' => env('OFFICE_LATITUDE', 0),
        'longitude' => env('OFFICE_LONGITUDE', 0),
        'radius' => env('OFFICE_RADIUS_METERS', 100),
    ],
    
    'office_start_time' => env('OFFICE_START_TIME', '09:00:00'),
    'office_end_time' => env('OFFICE_END_TIME', '18:00:00'),
    'grace_period_minutes' => env('GRACE_PERIOD_MINUTES', 15),
    
    'sync' => [
        'interval' => env('FINGERPRINT_SYNC_INTERVAL', 5),
        'auto_sync' => env('FINGERPRINT_AUTO_SYNC', true),
        'default_days_back' => env('FINGERPRINT_DEFAULT_DAYS_BACK', 7),
    ],
    
    'webhook' => [
        'verify_signature' => env('FINGERPRINT_WEBHOOK_VERIFY', false),
        'secret' => env('FINGERPRINT_WEBHOOK_SECRET', ''),
    ],
];