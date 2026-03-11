<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Mail\UserCreateMail;
use App\Models\User;
use App\Traits\FileUploadTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class UserController extends Controller implements HasMiddleware
{
    use FileUploadTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:User Index', only: ['index', 'show']),
            new Middleware('permission:User Create', only: ['store']),
            new Middleware('permission:User Update', only: ['update']),
            new Middleware('permission:User Delete', only: ['destroy']),
            new Middleware('permission:User Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = User::with(['employee', 'roles']);

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            }

            if ($request->has('user_type')) {
                $query->where('user_type', $request->user_type);
            }

            if ($request->has('role')) {
                $roleName = $request->role;
                $query->whereHas('roles', function ($q) use ($roleName) {
                    $q->where('name', $roleName);
                });
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $users = $query->paginate($perPage);

            // Transform data to hide pivot
            $users->getCollection()->transform(function ($user) {
                $userData = $user->toArray();
                if (isset($userData['roles'])) {
                    foreach ($userData['roles'] as &$role) {
                        unset($role['pivot']);
                    }
                }
                return $userData;
            });

            Log::info('Users index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'user_type', 'is_active', 'role']),
                'count' => $users->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Users retrieved successfully',
                'data' => $users
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve users: ' . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve users',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateUserRequest $request)
    {
        try {
            $currentUser = auth("api")->user();
            $data = $request->validated();

            $rawPassword = $data['password'];
            $data['password'] = Hash::make($rawPassword);

            // Business Logic: Admin users don't need employees
            if ($data['user_type'] === 'admin') {
                $data['employee_id'] = null;
            }

            // Handle Profile Image using trait
            $imagePath = $this->handleFileUpload($request, 'profile_image', null, 'users/profile', $data['username']);
            if ($imagePath) {
                $data['profile_image'] = $imagePath;
            }

            $user = User::create($data);

            // Assign Role
            if (isset($data['role'])) {
                $user->assignRole($data['role']);
            }

            // Send Welcome Email
            try {
                $emailData = [
                    'user' => $user->toArray(),
                    'password' => $rawPassword,
                    'role' => $data['role'] ?? null,
                    'created_by' => $currentUser ? $currentUser->name : 'System',
                    'login_url' => config('app.frontend_url') ?? config('app.url'),
                ];

                Mail::to($user->email)->send(new UserCreateMail($emailData));

                Log::info('User creation email sent', ['user_id' => $user->id]);
            } catch (\Throwable $th) {
                Log::error('Failed to send user creation email: ' . $th->getMessage());
            }

            $user->load([
                'roles' => function ($q) {
                    $q->select('id', 'name');
                },
                'employee'
            ]);

            Log::info('User created', [
                'admin_id' => Auth::id(),
                'created_user_id' => $user->id,
                'user_type' => $user->user_type
            ]);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User created successfully',
                'data' => $userData
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Failed to create user: ' . $th->getMessage());
            if (isset($imagePath)) {
                $this->deleteFile($imagePath);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create user',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $user = User::with(['employee', 'roles'])->find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            Log::info('User viewed', [
                'viewer_id' => Auth::id(),
                'viewed_user_id' => $user->id
            ]);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User retrieved successfully',
                'data' => $userData
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve user: ' . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve user',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateUserRequest $request, string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $data = $request->validated();

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            // Business Logic: Admin users don't need employees
            if (isset($data['user_type']) && $data['user_type'] === 'admin') {
                $data['employee_id'] = null;
            }

            // Handle Image Upload using trait
            $imagePath = $this->handleFileUpload($request, 'profile_image', $user->profile_image, 'users/profile', $user->username);
            if ($imagePath) {
                $data['profile_image'] = $imagePath;
            }

            $user->update($data);

            if (isset($data['role'])) {
                $user->syncRoles([$data['role']]);
            }

            $user->refresh();
            $user->load([
                'roles' => function ($q) {
                    $q->select('id', 'name');
                },
                'employee'
            ]);

            Log::info('User updated', [
                'admin_id' => Auth::id(),
                'updated_user_id' => $user->id,
                'updated_fields' => array_keys($data)
            ]);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User updated successfully',
                'data' => $userData
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update user: ' . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update user',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized user deletion attempt', [
                    'user_id' => Auth::id(),
                    'target_user_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete users'
                ], 403);
            }

            if ($user->id === Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You cannot delete your own account'
                ], 422);
            }

            $this->deleteFile($user->profile_image);
            $user->delete();

            Log::info('User deleted', [
                'admin_id' => Auth::id(),
                'deleted_user_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'User deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to delete user: ' . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete user',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            if ($user->id === Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You cannot deactivate your own account'
                ], 422);
            }

            $user->is_active = !$user->is_active;
            $user->save();

            Log::info('User status toggled', [
                'admin_id' => Auth::id(),
                'target_user_id' => $user->id,
                'new_status' => $user->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'User status updated successfully',
                'data' => [
                    'id' => $user->id,
                    'is_active' => $user->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to toggle user status: ' . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle user status',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
