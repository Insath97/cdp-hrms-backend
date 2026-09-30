<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePurposeCodeRequest;
use App\Http\Requests\UpdatePurposeCodeRequest;
use App\Models\PurposeCode;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PurposeCodeController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:PurposeCode Index', only: ['index', 'show']),
            new Middleware('permission:PurposeCode Create', only: ['store']),
            new Middleware('permission:PurposeCode Update', only: ['update']),
            new Middleware('permission:PurposeCode Delete', only: ['destroy']),
            new Middleware('permission:PurposeCode Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = PurposeCode::query();

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('is_default')) {
                $query->where('is_default', $request->boolean('is_default'));
            }

            $purposeCodes = $query->orderBy('code')->paginate($perPage);

            Log::info('Purpose codes index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'is_default', 'per_page']),
                'count' => $purposeCodes->count(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Purpose codes retrieved successfully',
                'data' => $purposeCodes,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve purpose codes', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve purpose codes',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function store(CreatePurposeCodeRequest $request)
    {
        try {
            $data = $request->validated();

            // Setting a new default must clear the previous one.
            if ($request->boolean('is_default')) {
                DB::table('purpose_codes')
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $purposeCode = PurposeCode::create($data);

            $this->logActivity('CREATE', 'PurposeCode', "Created purpose code: {$purposeCode->code} - {$purposeCode->description}", $data);

            Log::info('Purpose code created', [
                'user_id' => Auth::id(),
                'purpose_code_id' => $purposeCode->id,
                'code' => $purposeCode->code,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Purpose code created successfully',
                'data' => $purposeCode,
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Failed to create purpose code', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create purpose code',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function show(PurposeCode $purposeCode)
    {
        try {
            Log::info('Purpose code viewed', [
                'user_id' => Auth::id(),
                'purpose_code_id' => $purposeCode->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Purpose code retrieved successfully',
                'data' => $purposeCode,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve purpose code', [
                'user_id' => Auth::id(),
                'purpose_code_id' => $purposeCode->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve purpose code',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function update(UpdatePurposeCodeRequest $request, PurposeCode $purposeCode)
    {
        try {
            $old = $purposeCode->only(['code', 'description', 'is_default', 'is_active']);

            $data = $request->validated();

            // Setting a new default must clear the previous one.
            if (array_key_exists('is_default', $data) && $request->boolean('is_default')) {
                DB::table('purpose_codes')
                    ->where('id', '!=', $purposeCode->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $purposeCode->update($data);

            $this->logUpdate('PurposeCode', "Updated purpose code: {$purposeCode->code}", $old, $purposeCode->only(array_keys($old)));

            Log::info('Purpose code updated', [
                'user_id' => Auth::id(),
                'purpose_code_id' => $purposeCode->id,
                'updated_fields' => array_keys($data),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Purpose code updated successfully',
                'data' => $purposeCode->fresh(),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update purpose code', [
                'user_id' => Auth::id(),
                'purpose_code_id' => $purposeCode->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update purpose code',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function destroy(PurposeCode $purposeCode)
    {
        try {
            // The CUS report falls back to the 784001 default, so refuse to
            // remove the code it depends on.
            if ($purposeCode->is_default) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete the default purpose code. Set another code as default first.',
                ], 422);
            }

            $code = $purposeCode->code;
            $purposeCode->delete();

            $this->logActivity('DELETE', 'PurposeCode', "Deleted purpose code: {$code}");

            Log::info('Purpose code deleted', [
                'user_id' => Auth::id(),
                'purpose_code_id' => $purposeCode->id,
                'code' => $code,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Purpose code deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to delete purpose code', [
                'user_id' => Auth::id(),
                'purpose_code_id' => $purposeCode->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete purpose code',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function toggleStatus(PurposeCode $purposeCode)
    {
        try {
            // The default code must stay selectable for the CUS report.
            if ($purposeCode->is_default && $purposeCode->is_active) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot deactivate the default purpose code. Set another code as default first.',
                ], 422);
            }

            $purposeCode->is_active = !$purposeCode->is_active;
            $purposeCode->save();

            $statusStr = $purposeCode->is_active ? 'Activated' : 'Deactivated';
            $this->logActivity('TOGGLE_STATUS', 'PurposeCode', "{$statusStr} purpose code: {$purposeCode->code}");

            Log::info('Purpose code status toggled', [
                'user_id' => Auth::id(),
                'purpose_code_id' => $purposeCode->id,
                'new_status' => $purposeCode->is_active,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Purpose code status updated successfully',
                'data' => [
                    'id' => $purposeCode->id,
                    'is_active' => $purposeCode->is_active,
                ],
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to toggle purpose code status', [
                'user_id' => Auth::id(),
                'purpose_code_id' => $purposeCode->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle purpose code status',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
