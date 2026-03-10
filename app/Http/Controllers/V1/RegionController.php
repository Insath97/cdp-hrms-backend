<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRegionRequest;
use App\Http\Requests\UpdateRegionRequest;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class RegionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Region Index', only: ['index', 'show', 'getRegionList']),
            new Middleware('permission:Region Create', only: ['store']),
            new Middleware('permission:Region Update', only: ['update']),
            new Middleware('permission:Region Delete', only: ['destroy']),
            new Middleware('permission:Region Toggle Status', only: ['toggleStatus']),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Region::with('province');

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('province_id')) {
                $query->where('province_id', $request->province_id);
            }

            $regions = $query->paginate($perPage);

            Log::info('Regions index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'province_id', 'per_page']),
                'count' => $regions->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Regions retrieved successfully',
                'data' => $regions
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve regions', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve regions',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateRegionRequest $request)
    {
        try {
            $data = $request->validated();
            $region = Region::create($data);

            Log::info('Region created', [
                'user_id' => Auth::id(),
                'region_id' => $region->id,
                'region_name' => $region->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Region created successfully',
                'data' => $region
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Failed to create region', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create region',
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
            $region = Region::with('province')->find($id);

            if (!$region) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Region not found'
                ], 404);
            }

            Log::info('Region viewed', [
                'user_id' => Auth::id(),
                'region_id' => $region->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Region retrieved successfully',
                'data' => $region
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve region', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve region',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRegionRequest $request, string $id)
    {
        try {
            $region = Region::find($id);

            if (!$region) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Region not found'
                ], 404);
            }

            $data = $request->validated();
            $region->update($data);

            Log::info('Region updated', [
                'user_id' => Auth::id(),
                'region_id' => $region->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Region updated successfully',
                'data' => $region
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update region', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update region',
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
            $region = Region::find($id);

            if (!$region) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Region not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized region deletion attempt', [
                    'user_id' => Auth::id(),
                    'region_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete regions'
                ], 403);
            }

            $region->delete();

            Log::info('Region deleted', [
                'user_id' => Auth::id(),
                'region_id' => $id,
                'region_name' => $region->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Region deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to delete region', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete region',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $region = Region::find($id);

            if (!$region) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Region not found'
                ], 404);
            }

            $region->is_active = !$region->is_active;
            $region->save();

            Log::info('Region status toggled', [
                'user_id' => Auth::id(),
                'region_id' => $region->id,
                'new_status' => $region->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Region status updated successfully',
                'data' => [
                    'id' => $region->id,
                    'is_active' => $region->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle region status',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getRegionList(Request $request)
    {
        try {
            $query = Region::active();

            if ($request->has('province_id')) {
                $query->where('province_id', $request->province_id);
            }

            $regions = $query->select('id', 'name', 'code', 'province_id')
                ->with('province:id,name')
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Regions retrieved successfully',
                'data' => $regions
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve region list', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve regions',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
