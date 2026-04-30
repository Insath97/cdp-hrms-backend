<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLetterRequest;
use App\Http\Requests\UpdatePermissionRequest;
use App\Http\Requests\UpdateLetterRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\Letter;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class LetterController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Letter Index', only: ['index', 'show', 'getLetterList']),
            new Middleware('permission:Letter Create', only: ['store']),
            new Middleware('permission:Letter Update', only: ['update']),
            new Middleware('permission:Letter Delete', only: ['destroy']),
            new Middleware('permission:Letter Toggle Status', only: ['toggleStatus']),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Letter::query();

            if ($request->has('search')) {
                $query->search($request->search);
            }

            // if ($request->has('is_active')) {
            //     $query->where('is_active', $request->boolean('is_active'));
            // }

            $letters = $query->paginate($perPage);

            Log::info('Letters index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'per_page']),
                'count' => $letters->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Letters retrieved successfully',
                'data' => $letters->load(['designation'])
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve letters', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve letters',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateLetterRequest $request)
    {
        try {
            $data = $request->validated();
            $data['ref_number'] = Letter::generateNextRefNumber();
            $letter = Letter::create($data);

            Log::info('Letter created', [
                'user_id' => Auth::id(),
                'letter_id' => $letter->id,
                'ref_number' => $letter->ref_number,
                'title' => $letter->title,
                'employee_name' => $letter->employee_name,
                'address_line1' => $letter->address_line1,
                'address_line2' => $letter->address_line2,
                'city' => $letter->city,
                'department_id' => $letter->department_id,
                'designation_id' => $letter->designation_id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Letter created successfully',
                'data' => $letter
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Failed to create letter', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create letter',
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
            $letter = Letter::find($id);

            if (!$letter) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Letter not found'
                ], 404);
            }

            Log::info('Letter viewed', [
                'user_id' => Auth::id(),
                'letter_id' => $letter->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Letter retrieved successfully',
                'data' => $letter
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve letter', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve letter',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateLetterRequest $request, string $id)
    {
        try {
            $letter = Letter::find($id);

            if (!$letter) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Letter not found'
                ], 404);
            }

            $data = $request->validated();
            $letter->update($data);

            Log::info('Letter updated', [
                'user_id' => Auth::id(),
                'letter_id' => $letter->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Letter updated successfully',
                'data' => $letter
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update letter', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update letter',
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
            $letter = Letter::find($id);

            if (!$letter) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Letter not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized letter deletion attempt', [
                    'user_id' => Auth::id(),
                    'letter_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete letters'
                ], 403);
            }

            $letter->delete();

            Log::info('Letter deleted', [
                'user_id' => Auth::id(),
                'letter_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Letter deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to delete letter', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete letter',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    // public function toggleStatus(string $id)
    // {
    //     try {
    //         $letter = Letter::find($id);

    //         if (!$letter) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => 'Letter not found'
    //             ], 404);
    //         }

    //         $letter->is_active = !$letter->is_active;
    //         $letter->save();

    //         Log::info('Letter status toggled', [
    //             'user_id' => Auth::id(),
    //             'letter_id' => $letter->id,
    //             'new_status' => $letter->is_active
    //         ]);

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Letter status updated successfully',
    //             'data' => [
    //                 'id' => $letter->id,
    //                 'is_active' => $letter->is_active
    //             ]
    //         ], 200);
    //     } catch (\Throwable $th) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Failed to toggle letter status',
    //             'error' => $th->getMessage()
    //         ], 500);
    //     }
    // }

    public function getLetterList()
    {
        try {
            $letters = Letter::active()
                ->select('id', 'title', 'content')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Letters retrieved successfully',
                'data' => $letters
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve letter list', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve letters',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
