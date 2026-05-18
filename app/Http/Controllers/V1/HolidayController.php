<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class HolidayController extends Controller
{
    /**
     * Display a listing of holidays.
     */
    public function index(Request $request)
    {
        $year = $request->get('year', date('Y'));
        $holidays = Holiday::where('year', $year)->orderBy('date')->get();

        return response()->json([
            'status' => 'success',
            'data' => $holidays
        ]);
    }

    /**
     * Sync holidays from the frontend NPM package data.
     */
    public function sync(Request $request)
    {
        // Handle both direct array or wrapped 'holidays' key
        $incomingHolidays = $request->input('holidays');
        
        if (!$incomingHolidays) {
            // If No 'holidays' key, check if the entire body is an array
            $incomingHolidays = $request->json()->all();
        }

        // Filter out any non-array items just in case
        if (is_array($incomingHolidays)) {
            $incomingHolidays = array_filter($incomingHolidays, function($item) {
                return is_array($item) || is_object($item);
            });
        }

        if (!$incomingHolidays || !is_array($incomingHolidays)) {
            Log::warning('Holiday sync attempt with invalid data', ['data' => $request->all()]);
            return response()->json([
                'status' => 'error',
                'message' => 'The request must contain an array of holidays.'
            ], 422);
        }

        $processedCount = 0;

        try {
            DB::beginTransaction();

            foreach ($incomingHolidays as $item) {
                $item = (array) $item;
                // Parse date from formats like "2026-01-01 00:00:00"
                if (!isset($item['date'])) continue;

                $date = Carbon::parse($item['date']);
                $year = $date->year;

                Holiday::updateOrCreate(
                    ['date' => $date->toDateString()],
                    [
                        'name' => $item['name'] ?? 'Unnamed Holiday',
                        'type' => $item['type'] ?? 'public',
                        'year' => $year,
                        // Defaults to true, but can be customized
                        'is_company_holiday' => true, 
                    ]
                );
                $processedCount++;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Successfully synced $processedCount holidays.",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Holiday sync failed: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to sync holidays: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created holiday.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date|unique:holidays,date',
            'name' => 'required|string|max:255',
            'type' => 'nullable|string',
            'is_company_holiday' => 'boolean',
            'description' => 'nullable|string',
        ]);

        $validated['year'] = Carbon::parse($validated['date'])->year;

        $holiday = Holiday::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Holiday created successfully.',
            'data' => $holiday
        ], 201);
    }
}
