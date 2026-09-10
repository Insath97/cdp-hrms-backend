<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Geofence;
use App\Models\User;
use App\Models\UserAllowedLocation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;

class UserAllowedLocationController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Geofence Manage', only: ['index', 'store', 'update', 'destroy', 'assignToUser', 'assignGeofences']),
        ];
    }

    public function index()
    {
        return response()->json([
            'status' => 'success',
            'data' => UserAllowedLocation::with(['user:id,name', 'geofence'])->get(),
        ]);
    }

    public function myLocations()
    {
        return response()->json([
            'status' => 'success',
            'data' => Auth::user()->allowedLocations()->where('is_active', true)->with('geofence')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'geofence_id' => 'required|exists:geofences,id',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $data = $request->only(['geofence_id']);
        if ($request->filled('user_id')) {
            $data['user_id'] = $request->user_id;
        }
        $location = UserAllowedLocation::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Allowed location created successfully',
            'data' => $location->load('geofence'),
        ], 201);
    }

    public function update(Request $request, UserAllowedLocation $userAllowedLocation)
    {
        $request->validate([
            'geofence_id' => 'sometimes|exists:geofences,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $userAllowedLocation->update($request->only(['geofence_id', 'is_active']));

        return response()->json([
            'status' => 'success',
            'message' => 'Allowed location updated successfully',
            'data' => $userAllowedLocation->load('geofence'),
        ]);
    }

    public function destroy(UserAllowedLocation $userAllowedLocation)
    {
        $userAllowedLocation->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Allowed location deleted successfully',
        ]);
    }

    public function assignToUser(Request $request, User $user)
    {
        $request->validate([
            'location_ids' => 'required|array',
            'location_ids.*' => 'exists:user_allowed_locations,id',
        ]);

        UserAllowedLocation::whereIn('id', $request->location_ids)
            ->update(['user_id' => $user->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Locations assigned to user successfully',
            'data' => $user->allowedLocations()->pluck('id'),
        ]);
    }

    public function assignGeofences(Request $request, User $user)
    {
        $request->validate([
            'geofence_ids' => 'required|array',
            'geofence_ids.*' => 'exists:geofences,id',
        ]);

        $created = [];
        foreach ($request->geofence_ids as $gid) {
            $location = UserAllowedLocation::firstOrCreate([
                'user_id' => $user->id,
                'geofence_id' => $gid,
            ]);
            $created[] = $location->load('geofence');
        }

        return response()->json([
            'status' => 'success',
            'message' => count($created) . ' geofence(s) added as allowed locations',
            'data' => $created,
        ], 201);
    }

    public function checkLocation(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $user = Auth::user();
        $latitude = (float) $request->latitude;
        $longitude = (float) $request->longitude;

        $allowedLocations = $user->allowedLocations()
            ->where('is_active', true)
            ->with('geofence')
            ->get();

        if ($allowedLocations->isEmpty()) {
            return response()->json([
                'status' => 'success',
                'within_allowed' => false,
                'message' => 'No allowed locations assigned. Please contact your administrator.',
                'location' => null,
            ]);
        }

        $closestDistance = PHP_FLOAT_MAX;
        $closestName = null;
        $closestRadius = null;

        foreach ($allowedLocations as $loc) {
            $gf = $loc->geofence;
            if (!$gf || !$gf->is_active) continue;

            $distance = $this->haversineDistance(
                $latitude,
                $longitude,
                (float) $gf->latitude,
                (float) $gf->longitude
            );

            if ($distance <= (float) $gf->radius_meters) {
                return response()->json([
                    'status' => 'success',
                    'within_allowed' => true,
                    'message' => 'You are within your allowed location: ' . $gf->name,
                    'location' => [
                        'id' => $gf->id,
                        'name' => $gf->name,
                    ],
                ]);
            }

            if ($distance < $closestDistance) {
                $closestDistance = $distance;
                $closestName = $gf->name;
                $closestRadius = (float) $gf->radius_meters;
            }
        }

        return response()->json([
            'status' => 'success',
            'within_allowed' => false,
            'message' => 'Outside allowed locations. Closest "' . ($closestName ?? 'N/A') . '" is ' . round($closestDistance) . 'm away (radius: ' . ($closestRadius ?? 'N/A') . 'm).',
            'location' => null,
            'debug' => [
                'closest_distance_meters' => round($closestDistance),
                'closest_location' => $closestName,
                'closest_location_radius' => $closestRadius,
            ],
        ]);
    }

    private function haversineDistance($lat1, $lon1, $lat2, $lon2)
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
