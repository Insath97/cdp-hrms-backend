<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBankRequest;
use App\Http\Requests\UpdateBankRequest;
use App\Models\Bank;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class BankController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Bank Index', only: ['index', 'show']),
            new Middleware('permission:Bank Create', only: ['store']),
            new Middleware('permission:Bank Update', only: ['update']),
            new Middleware('permission:Bank Delete', only: ['destroy']),
            new Middleware('permission:Bank Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Bank::query()->withCount('employees');

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('is_commercial')) {
                $query->where('is_commercial', $request->boolean('is_commercial'));
            }

            $banks = $query->orderBy('name')->paginate($perPage);

            Log::info('Banks index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'is_commercial', 'per_page']),
                'count' => $banks->count(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Banks retrieved successfully',
                'data' => $banks,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve banks', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve banks',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function store(CreateBankRequest $request)
    {
        try {
            $data = $request->validated();
            $bank = Bank::create($data);

            $this->logActivity('CREATE', 'Bank', "Created bank: {$bank->name}", $data);

            Log::info('Bank created', [
                'user_id' => Auth::id(),
                'bank_id' => $bank->id,
                'bank_name' => $bank->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Bank created successfully',
                'data' => $bank,
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Failed to create bank', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create bank',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function show(Bank $bank)
    {
        try {
            $bank->loadCount('employees');

            Log::info('Bank viewed', [
                'user_id' => Auth::id(),
                'bank_id' => $bank->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Bank retrieved successfully',
                'data' => $bank,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve bank', [
                'user_id' => Auth::id(),
                'bank_id' => $bank->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve bank',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateBankRequest $request, Bank $bank)
    {
        try {
            $old = $bank->only(['name', 'swift_code', 'account_number_length', 'is_commercial', 'is_active']);

            $data = $request->validated();
            $bank->update($data);

            // Keep the denormalised bank_name on employees in sync.
            if (array_key_exists('name', $data)) {
                DB::table('employees')
                    ->where('bank_id', $bank->id)
                    ->update(['bank_name' => $bank->name]);
            }

            $this->logUpdate('Bank', "Updated bank: {$bank->name}", $old, $bank->only(array_keys($old)));

            Log::info('Bank updated', [
                'user_id' => Auth::id(),
                'bank_id' => $bank->id,
                'updated_fields' => array_keys($data),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Bank updated successfully',
                'data' => $bank->fresh(),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update bank', [
                'user_id' => Auth::id(),
                'bank_id' => $bank->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update bank',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function destroy(Bank $bank)
    {
        try {
            // Employees keep their account details, so block deletion while in use.
            if ($bank->employees()->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete a bank that is assigned to employees. Deactivate it instead.',
                ], 422);
            }

            $name = $bank->name;
            $bank->delete();

            $this->logActivity('DELETE', 'Bank', "Deleted bank: {$name}");

            Log::info('Bank deleted', [
                'user_id' => Auth::id(),
                'bank_id' => $bank->id,
                'bank_name' => $name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Bank deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to delete bank', [
                'user_id' => Auth::id(),
                'bank_id' => $bank->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete bank',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function toggleStatus(Bank $bank)
    {
        try {
            $bank->is_active = !$bank->is_active;
            $bank->save();

            $statusStr = $bank->is_active ? 'Activated' : 'Deactivated';
            $this->logActivity('TOGGLE_STATUS', 'Bank', "{$statusStr} bank: {$bank->name}");

            Log::info('Bank status toggled', [
                'user_id' => Auth::id(),
                'bank_id' => $bank->id,
                'new_status' => $bank->is_active,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Bank status updated successfully',
                'data' => [
                    'id' => $bank->id,
                    'is_active' => $bank->is_active,
                ],
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to toggle bank status', [
                'user_id' => Auth::id(),
                'bank_id' => $bank->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle bank status',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
