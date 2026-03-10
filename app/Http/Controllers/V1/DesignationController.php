<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDesignationRequest;
use App\Http\Requests\UpdateDesignationRequest;
use App\Models\Designation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DesignationController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Designation Index', only: ['index', 'show', 'getDesignationList']),
            new Middleware('permission:Designation Create', only: ['store']),
            new Middleware('permission:Designation Update', only: ['update']),
            new Middleware('permission:Designation Delete', only: ['destroy']),
            new Middleware('permission:Designation Toggle Status', only: ['toggleStatus']),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Designation::with('department');

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            if ($request->has('level')) {
                $query->where('level', $request->level);
            }

            $designations = $query->paginate($perPage);

            Log::info('Designations index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'department_id', 'level', 'per_page']),
                'count' => $designations->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Designations retrieved successfully',
                'data' => $designations
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve designations', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve designations',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateDesignationRequest $request)
    {
        try {
            $data = $request->validated();
            $designation = Designation::create($data);

            Log::info('Designation created', [
                'user_id' => Auth::id(),
                'designation_id' => $designation->id,
                'designation_name' => $designation->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Designation created successfully',
                'data' => $designation
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Failed to create designation', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create designation',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $designation = Designation::with('department')->find($id);

            if (!$designation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Designation not found'
                ], 404);
            }

            Log::info('Designation viewed', [
                'user_id' => Auth::id(),
                'designation_id' => $designation->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Designation retrieved successfully',
                'data' => $designation
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve designation', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve designation',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDesignationRequest $request, string $id)
    {
        try {
            $designation = Designation::find($id);

            if (!$designation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Designation not found'
                ], 404);
            }

            $data = $request->validated();
            $designation->update($data);

            Log::info('Designation updated', [
                'user_id' => Auth::id(),
                'designation_id' => $designation->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Designation updated successfully',
                'data' => $designation
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update designation', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update designation',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $designation = Designation::find($id);

            if (!$designation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Designation not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized designation deletion attempt', [
                    'user_id' => Auth::id(),
                    'designation_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete designations'
                ], 403);
            }

            $designation->delete();

            Log::info('Designation deleted', [
                'user_id' => Auth::id(),
                'designation_id' => $id,
                'designation_name' => $designation->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Designation deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to delete designation', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete designation',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $designation = Designation::find($id);

            if (!$designation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Designation not found'
                ], 404);
            }

            $designation->is_active = !$designation->is_active;
            $designation->save();

            Log::info('Designation status toggled', [
                'user_id' => Auth::id(),
                'designation_id' => $designation->id,
                'new_status' => $designation->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Designation status updated successfully',
                'data' => [
                    'id' => $designation->id,
                    'is_active' => $designation->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle designation status',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getDesignationList(Request $request)
    {
        try {
            $query = Designation::active();

            if ($request->has('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            $designations = $query->select('id', 'name', 'code', 'department_id', 'level')
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Designations retrieved successfully',
                'data' => $designations
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve designation list', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve designations',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
