<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\OpenLocationMember;
use App\Models\OpenLocationRequest;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpenLocationController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Open Location Manage', only: [
                'index', 'approve', 'reject', 'members', 'revoke',
            ]),
        ];
    }

    /**
     * Submit an open-location request (self, or on behalf of another user by HR).
     */
    public function request(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|integer|exists:users,id',
                'reason' => 'nullable|string|max:2000',
            ]);

            $actor = Auth::user();
            $targetId = $validated['user_id'] ?? $actor->id;

            if ($targetId !== $actor->id && ! $actor->can('Open Location Manage')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized to request open location for another user',
                ], 403);
            }

            $target = User::findOrFail($targetId);

            if ($target->hasOpenLocationAccess()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User already has open-location access',
                ], 400);
            }

            $pending = OpenLocationRequest::where('user_id', $target->id)
                ->where('status', OpenLocationRequest::STATUS_PENDING)
                ->exists();

            if ($pending) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A pending open-location request already exists for this user',
                ], 400);
            }

            $openLocationRequest = OpenLocationRequest::create([
                'user_id' => $target->id,
                'employee_id' => $target->employee?->id,
                'requested_by' => $actor->id,
                'reason' => $validated['reason'] ?? null,
                'status' => OpenLocationRequest::STATUS_PENDING,
            ]);

            $this->logActivity(
                'REQUEST',
                'Open Location',
                "Open-location access requested for user #{$target->id}",
                ['request_id' => $openLocationRequest->id, 'user_id' => $target->id]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Open-location request submitted successfully',
                'data' => $openLocationRequest->load(['user', 'employee', 'requestedBy']),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $th) {
            Log::error('Failed to create open-location request', ['error' => $th->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create open-location request',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Requests submitted for the authenticated user.
     */
    public function myRequests()
    {
        try {
            $userId = Auth::id();

            $requests = OpenLocationRequest::with(['user', 'employee', 'approvedBy', 'rejectedBy'])
                ->where('user_id', $userId)
                ->orWhere('requested_by', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $requests,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve open-location requests', ['error' => $th->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve open-location requests',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * All open-location requests (HR view).
     */
    public function index(Request $request)
    {
        try {
            $query = OpenLocationRequest::with(['user', 'employee', 'requestedBy', 'approvedBy', 'rejectedBy']);

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            $requests = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

            return response()->json([
                'status' => 'success',
                'message' => 'Open-location requests retrieved successfully',
                'data' => $requests,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve open-location requests', ['error' => $th->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve open-location requests',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve a request and add the user to the open-location members.
     */
    public function approve(string $id)
    {
        try {
            $openLocationRequest = OpenLocationRequest::find($id);

            if (! $openLocationRequest) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Request not found',
                ], 404);
            }

            if ($openLocationRequest->status !== OpenLocationRequest::STATUS_PENDING) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Request is already '.$openLocationRequest->status,
                ], 400);
            }

            $actor = Auth::user();

            DB::transaction(function () use ($openLocationRequest, $actor) {
                $openLocationRequest->update([
                    'status' => OpenLocationRequest::STATUS_APPROVED,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'rejected_by' => null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ]);

                OpenLocationMember::updateOrCreate(
                    ['user_id' => $openLocationRequest->user_id],
                    [
                        'employee_id' => $openLocationRequest->employee_id,
                        'request_id' => $openLocationRequest->id,
                        'approved_by' => $actor->id,
                        'approved_at' => now(),
                        'is_active' => true,
                    ]
                );
            });

            $this->logActivity(
                'APPROVE',
                'Open Location',
                "Open-location access approved for user #{$openLocationRequest->user_id}",
                ['request_id' => $openLocationRequest->id, 'user_id' => $openLocationRequest->user_id]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Open-location request approved successfully',
                'data' => $openLocationRequest->fresh()->load(['user', 'employee', 'approvedBy']),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to approve open-location request', ['error' => $th->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve open-location request',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Whether the authenticated user currently has open-location access.
     */
    public function status()
    {
        try {
            $user = Auth::user();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'has_access' => $user->hasOpenLocationAccess(),
                ],
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve open-location status', ['error' => $th->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve open-location status',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a request.
     */
    public function reject(Request $request, string $id)
    {
        try {
            $validated = $request->validate([
                'rejection_reason' => 'nullable|string|max:2000',
            ]);

            $openLocationRequest = OpenLocationRequest::find($id);

            if (! $openLocationRequest) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Request not found',
                ], 404);
            }

            if ($openLocationRequest->status !== OpenLocationRequest::STATUS_PENDING) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Request is already '.$openLocationRequest->status,
                ], 400);
            }

            $actor = Auth::user();

            $openLocationRequest->update([
                'status' => OpenLocationRequest::STATUS_REJECTED,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $validated['rejection_reason'] ?? null,
            ]);

            $this->logActivity(
                'REJECT',
                'Open Location',
                "Open-location access rejected for user #{$openLocationRequest->user_id}",
                ['request_id' => $openLocationRequest->id, 'user_id' => $openLocationRequest->user_id]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Open-location request rejected successfully',
                'data' => $openLocationRequest->fresh()->load(['user', 'employee', 'rejectedBy']),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $th) {
            Log::error('Failed to reject open-location request', ['error' => $th->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject open-location request',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Currently active open-location members.
     */
    public function members()
    {
        try {
            $members = OpenLocationMember::with(['user', 'employee', 'approvedBy'])
                ->where('is_active', true)
                ->orderBy('approved_at', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $members,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve open-location members', ['error' => $th->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve open-location members',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Revoke a user's open-location access.
     */
    public function revoke(User $user)
    {
        try {
            $member = OpenLocationMember::where('user_id', $user->id)->first();

            if (! $member) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not an open-location member',
                ], 404);
            }

            $member->update(['is_active' => false]);

            $this->logActivity(
                'REVOKE',
                'Open Location',
                "Open-location access revoked for user #{$user->id}",
                ['user_id' => $user->id]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Open-location access revoked successfully',
                'data' => $member->fresh()->load(['user', 'employee']),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to revoke open-location access', ['error' => $th->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to revoke open-location access',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
