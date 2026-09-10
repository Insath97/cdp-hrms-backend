<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\WeekendHolidayWork;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WeekendHolidayWorkController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = Auth::user();

            $query = WeekendHolidayWork::with(['user', 'employee'])
                ->orderBy('date', 'desc');

            if (! $user->hasPermissionTo('Attendance View All')) {
                $query->where('user_id', $user->id);
            }

            if ($request->has('date')) {
                $query->where('date', $request->date);
            }

            if ($request->has('from_date')) {
                $query->where('date', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->where('date', '<=', $request->to_date);
            }

            if ($request->has('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }

            $records = $query->paginate($request->get('per_page', 50));

            return response()->json([
                'status' => 'success',
                'data' => $records,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch records: ' . $th->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'date' => 'required|date',
                'clock_in' => 'required|date_format:H:i:s',
                'clock_out' => 'nullable|date_format:H:i:s',
                'description' => 'required|string|max:1000',
            ]);

            $user = Auth::user();
            $employee = $user->employee;

            $existing = WeekendHolidayWork::where('user_id', $user->id)
                ->where('date', $validated['date'])
                ->first();

            if ($existing) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You already have a record for this date',
                ], 409);
            }

            $record = WeekendHolidayWork::create([
                'user_id' => $user->id,
                'employee_id' => $employee?->id,
                'date' => $validated['date'],
                'clock_in' => $validated['clock_in'],
                'clock_out' => $validated['clock_out'],
                'description' => $validated['description'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Weekend/holiday work recorded successfully',
                'data' => $record->load(['user', 'employee']),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create record: ' . $th->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $record = WeekendHolidayWork::with(['user', 'employee'])->findOrFail($id);

            $user = Auth::user();
            if (! $user->hasPermissionTo('Attendance View All') && $record->user_id !== $user->id) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }

            return response()->json([
                'status' => 'success',
                'data' => $record,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Record not found',
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $record = WeekendHolidayWork::findOrFail($id);

            $user = Auth::user();
            if ($record->user_id !== $user->id) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }

            $validated = $request->validate([
                'clock_in' => 'required|date_format:H:i:s',
                'clock_out' => 'nullable|date_format:H:i:s',
                'description' => 'required|string|max:1000',
            ]);

            $record->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Record updated successfully',
                'data' => $record->load(['user', 'employee']),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update record: ' . $th->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $record = WeekendHolidayWork::findOrFail($id);

            $user = Auth::user();
            if ($record->user_id !== $user->id) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }

            $record->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Record deleted successfully',
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete record',
            ], 500);
        }
    }

    public function checkDate(Request $request)
    {
        try {
            $request->validate(['date' => 'required|date']);

            $date = \Carbon\Carbon::parse($request->date);
            $isWeekend = $date->isWeekend();
            $holiday = Holiday::where('date', $date->toDateString())
                ->where('is_company_holiday', true)
                ->first();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'is_weekend' => $isWeekend,
                    'is_holiday' => (bool) $holiday,
                    'is_eligible' => $isWeekend || (bool) $holiday,
                    'holiday_name' => $holiday?->name,
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to check date: ' . $th->getMessage(),
            ], 500);
        }
    }
}
