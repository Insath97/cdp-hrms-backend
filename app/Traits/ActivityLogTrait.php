<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait ActivityLogTrait
{
    /**
     * Log activity to database and Laravel log file.
     *
     * @param string $action
     * @param string $module
     * @param string $description
     * @param array|null $payload
     * @return void
     */
    public function logActivity(string $action, string $module, string $description, ?array $payload = null)
    {
        try {
            $userId = null;
            if (Auth::guard('api')->check()) {
                $userId = Auth::guard('api')->id();
            } elseif (Auth::guard('sanctum')->check()) {
                $userId = Auth::guard('sanctum')->id();
            } else {
                $userId = Auth::id();
            }

            // DB logging
            ActivityLog::create([
                'user_id' => $userId,
                'action' => $action,
                'module' => $module,
                'description' => $description,
                'payload' => $payload,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            // Laravel file logging
            Log::info("[{$module}] {$action}: {$description} | User ID: " . ($userId ?? 'Guest') . " | IP: " . request()->ip());

        } catch (\Throwable $th) {
            // Silently fail DB logging but log the failure to Laravel logs
            Log::error("Failed to log activity to database: " . $th->getMessage());
            Log::info("[{$module}] {$action}: {$description} (DB Log Failed)");
        }
    }

    /**
     * Log an update activity with old and new data.
     */
    public function logUpdate(string $module, string $description, array $old, array $new)
    {
        $this->logActivity('UPDATE', $module, $description, [
            'old' => $old,
            'new' => $new
        ]);
    }

    /**
     * Log a delete activity with old data.
     */
    public function logDelete(string $module, string $description, array $old)
    {
        $this->logActivity('DELETE', $module, $description, [
            'old' => $old,
            'new' => null
        ]);
    }

    /**
     * Log a toggle status activity with old and new data.
     */
    public function logToggleStatus(string $module, string $description, array $old, array $new)
    {
        $this->logActivity('TOGGLE_STATUS', $module, $description, [
            'old' => $old,
            'new' => $new
        ]);
    }
}
