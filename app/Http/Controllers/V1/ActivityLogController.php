<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ActivityLogController extends Controller implements HasMiddleware
{
    /**
     * Get the middleware assigned to the controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Activity Log Index', only: ['index']),
            new Middleware('permission:Activity Log Show', only: ['show']),
        ];
    }

    /**
     * Display a listing of the activity logs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = ActivityLog::with('user:id,name,email,profile_image');

            // Search by user name, email, or description
            if ($request->has('search') && $request->search != '') {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('description', 'LIKE', "%{$search}%")
                      ->orWhereHas('user', function ($q) use ($search) {
                          $q->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                        });
                });
            }

            // Filter by module
            if ($request->has('module') && $request->module != '') {
                $query->where('module', $request->module);
            }

            // Filter by action
            if ($request->has('action') && $request->action != '') {
                $query->where('action', $request->action);
            }

            // Filter by date range
            if ($request->has('start_date') && $request->start_date != '') {
                $query->whereDate('created_at', '>=', $request->start_date);
            }
            if ($request->has('end_date') && $request->end_date != '') {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            // Ordering
            $query->orderBy('created_at', 'desc');

            $logs = $query->paginate($perPage);

            $logs->getCollection()->transform(function ($log) {
                if ($log->module === 'Employee' && is_array($log->payload)) {
                    $payload = $log->payload;
                    $allowedKeys = ['f_name', 'l_name', 'employee_code', 'reporting_manager', 'reporting_manager_id'];
                    $allowedFlip = array_flip($allowedKeys);

                    if (array_key_exists('old', $payload) || array_key_exists('new', $payload)) {
                        if (isset($payload['old']) && is_array($payload['old'])) {
                            $payload['old'] = array_intersect_key($payload['old'], $allowedFlip);
                        }
                        if (isset($payload['new']) && is_array($payload['new'])) {
                            $payload['new'] = array_intersect_key($payload['new'], $allowedFlip);
                        }
                        $log->payload = $payload;
                    } else {
                        $log->payload = array_intersect_key($payload, $allowedFlip);
                    }
                }
                return $log;
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Activity logs retrieved successfully',
                'data' => $logs
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve activity logs',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Display the specified activity log.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $log = ActivityLog::with('user:id,name,email,profile_image')->find($id);

            if (!$log) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Activity log not found',
                ], 404);
            }

            if ($log->module === 'Employee' && is_array($log->payload)) {
                $payload = $log->payload;
                $allowedKeys = ['f_name', 'l_name', 'employee_code', 'reporting_manager', 'reporting_manager_id'];
                $allowedFlip = array_flip($allowedKeys);

                if (array_key_exists('old', $payload) || array_key_exists('new', $payload)) {
                    if (isset($payload['old']) && is_array($payload['old'])) {
                        $payload['old'] = array_intersect_key($payload['old'], $allowedFlip);
                    }
                    if (isset($payload['new']) && is_array($payload['new'])) {
                        $payload['new'] = array_intersect_key($payload['new'], $allowedFlip);
                    }
                    $log->payload = $payload;
                } else {
                    $log->payload = array_intersect_key($payload, $allowedFlip);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Activity log retrieved successfully',
                'data' => $log
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve activity log',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
