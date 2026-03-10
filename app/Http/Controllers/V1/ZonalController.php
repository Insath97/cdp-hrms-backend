<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateZonalRequest;
use App\Http\Requests\UpdateZonalRequest;
use App\Models\Zonal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ZonalController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Zonal Index', only: ['index', 'show', 'getZonalList']),
            new Middleware('permission:Zonal Create', only: ['store']),
            new Middleware('permission:Zonal Update', only: ['update']),
            new Middleware('permission:Zonal Delete', only: ['destroy']),
            new Middleware('permission:Zonal Toggle Status', only: ['toggleStatus']),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Zonal::with('region');

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('region_id')) {
                $query->where('region_id', $request->region_id);
            }

            $zonals = $query->paginate($perPage);

            Log::info('Zonals index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'region_id', 'per_page']),
                'count' => $zonals->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zonals retrieved successfully',
                'data' => $zonals
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve zonals', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve zonals',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateZonalRequest $request)
    {
        try {
            $data = $request->validated();
            $zonal = Zonal::create($data);

            Log::info('Zonal created', [
                'user_id' => Auth::id(),
                'zonal_id' => $zonal->id,
                'zonal_name' => $zonal->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zonal created successfully',
                'data' => $zonal
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Failed to create zonal', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create zonal',
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
            $zonal = Zonal::with('region')->find($id);

            if (!$zonal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Zonal not found'
                ], 404);
            }

            Log::info('Zonal viewed', [
                'user_id' => Auth::id(),
                'zonal_id' => $zonal->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zonal retrieved successfully',
                'data' => $zonal
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve zonal', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve zonal',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateZonalRequest $request, string $id)
    {
        try {
            $zonal = Zonal::find($id);

            if (!$zonal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Zonal not found'
                ], 404);
            }

            $data = $request->validated();
            $zonal->update($data);

            Log::info('Zonal updated', [
                'user_id' => Auth::id(),
                'zonal_id' => $zonal->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zonal updated successfully',
                'data' => $zonal
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update zonal', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update zonal',
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
            $zonal = Zonal::find($id);

            if (!$zonal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Zonal not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized zonal deletion attempt', [
                    'user_id' => Auth::id(),
                    'zonal_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete zonals'
                ], 403);
            }

            $zonal->delete();

            Log::info('Zonal deleted', [
                'user_id' => Auth::id(),
                'zonal_id' => $id,
                'zonal_name' => $zonal->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zonal deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to delete zonal', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete zonal',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $zonal = Zonal::find($id);

            if (!$zonal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Zonal not found'
                ], 404);
            }

            $zonal->is_active = !$zonal->is_active;
            $zonal->save();

            Log::info('Zonal status toggled', [
                'user_id' => Auth::id(),
                'zonal_id' => $zonal->id,
                'new_status' => $zonal->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zonal status updated successfully',
                'data' => [
                    'id' => $zonal->id,
                    'is_active' => $zonal->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle zonal status',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getZonalList(Request $request)
    {
        try {
            $query = Zonal::active();

            if ($request->has('region_id')) {
                $query->where('region_id', $request->region_id);
            }

            $zonals = $query->select('id', 'name', 'code', 'region_id')
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Zonals retrieved successfully',
                'data' => $zonals
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve zonal list', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve zonals',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
