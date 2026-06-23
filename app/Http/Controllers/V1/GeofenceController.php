<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Geofence;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;

class GeofenceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Geofence Manage', only: ['index', 'store', 'update', 'destroy', 'assignUsers', 'getUserGeofences']),
        ];
    }

    public function index()
    {
        return response()->json([
            'status' => 'success',
            'data' => Geofence::all(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:branch,field_site,client_site,temporary',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius_meters' => 'required|numeric|min:10|max:10000',
        ]);

        $geofence = Geofence::create($request->only(['name', 'type', 'latitude', 'longitude', 'radius_meters']));

        return response()->json([
            'status' => 'success',
            'message' => 'Geofence created successfully',
            'data' => $geofence,
        ], 201);
    }

    public function update(Request $request, Geofence $geofence)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|in:branch,field_site,client_site,temporary',
            'latitude' => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
            'radius_meters' => 'sometimes|numeric|min:10|max:10000',
            'is_active' => 'sometimes|boolean',
        ]);

        $geofence->update($request->only(['name', 'type', 'latitude', 'longitude', 'radius_meters', 'is_active']));

        return response()->json([
            'status' => 'success',
            'message' => 'Geofence updated successfully',
            'data' => $geofence,
        ]);
    }

    public function destroy(Geofence $geofence)
    {
        $geofence->users()->detach();
        $geofence->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Geofence deleted successfully',
        ]);
    }

    public function assignUsers(Request $request, Geofence $geofence)
    {
        $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $pivotData = [];
        $users = User::whereIn('id', $request->user_ids)->get();
        foreach ($users as $user) {
            $pivotData[$user->id] = ['employee_id' => $user->employee_id];
        }

        $geofence->users()->sync($pivotData);

        return response()->json([
            'status' => 'success',
            'message' => 'Users assigned to geofence successfully',
            'data' => $geofence->users()->pluck('users.id'),
        ]);
    }

    public function getUserGeofences(User $user)
    {
        return response()->json([
            'status' => 'success',
            'data' => $user->geofences,
        ]);
    }

    public function myGeofences()
    {
        $user = Auth::user();

        return response()->json([
            'status' => 'success',
            'data' => $user->geofences,
        ]);
    }

    public static function checkCoordinates($latitude, $longitude, $user = null)
    {
        $user = $user ?? Auth::user();
        if (! $user) return false;

        $geofences = $user->geofences()->where('is_active', true)->get();

        foreach ($geofences as $geofence) {
            $distance = self::haversineDistance(
                (float) $latitude,
                (float) $longitude,
                (float) $geofence->latitude,
                (float) $geofence->longitude
            );

            if ($distance <= $geofence->radius_meters) {
                return $geofence;
            }
        }

        return false;
    }

    public static function haversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
