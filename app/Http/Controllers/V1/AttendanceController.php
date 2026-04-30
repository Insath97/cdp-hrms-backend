<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAttendanceRequest;
use App\Http\Requests\UpdateAttendanceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\Attendance;
use App\Models\User;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AttendanceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Attendance Index', only: ['index', 'show']),
            new Middleware('permission:Attendance Create', only: ['store']),
            new Middleware('permission:Attendance Update', only: ['update', 'clockOut']),
            new Middleware('permission:Attendance Delete', only: ['destroy']),
            new Middleware('permission:Attendance Report', only: ['dailyReport', 'weeklyReport', 'monthlyReport']),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Attendance::query();

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('employee_id')) {
                $query->byEmployee($request->employee_id);
            }

            if ($request->has('date')) {
                $query->byDate($request->date);
            }

            if ($request->has('from_date') && $request->has('to_date')) {
                $query->dateRange($request->from_date, $request->to_date);
            }

            $attendances = $query->with(['employee', 'user.employee'])->paginate($perPage);

            Log::info('Attendances index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'employee_id', 'date', 'from_date', 'to_date', 'per_page']),
                'count' => $attendances->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendances retrieved successfully',
                'data' => $attendances
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve attendances', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve attendances',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateAttendanceRequest $request)
    {
        try {
            $data = $request->validated();

            Log::info('Store method executed', ['data' => $data]);

            // Calculate working hours if both clock_in and clock_out are provided
            if ($data['clock_in'] && isset($data['clock_out']) && $data['clock_out']) {
                $data['working_hours'] = Attendance::calculateWorkingHours($data['clock_in'], $data['clock_out']);
                $data['status'] = 'present';
            } elseif ($data['clock_in']) {
                $data['status'] = 'present';
            }

            $attendance = Attendance::create($data);

            Log::info('Attendance created', [
                'user_id' => Auth::id(),
                'attendance_id' => $attendance->id,
                'employee_id' => $attendance->employee_id,
                'user_id' => $attendance->user_id,
                'date' => $attendance->date,
                'clock_in' => $attendance->clock_in,
                'clock_out' => $attendance->clock_out,
                'working_hours' => $attendance->working_hours,
                'status' => $attendance->status,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendance created successfully',
                'data' => $attendance->load('employee', 'user')
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Failed to create attendance', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create attendance',
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
            $attendance = Attendance::with('employee', 'user')->find($id);

            if (!$attendance) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Attendance not found'
                ], 404);
            }

            Log::info('Attendance viewed', [
                'user_id' => Auth::id(),
                'attendance_id' => $attendance->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendance retrieved successfully',
                'data' => $attendance->load('employee', 'user')
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to retrieve attendance', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve attendance',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAttendanceRequest $request, string $id)
    {
        try {
            $attendance = Attendance::find($id);

            if (!$attendance) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Attendance not found'
                ], 404);
            }

            $data = $request->validated();

            // Determine if user_id update is requested and whether it's safe
            $allowUserIdUpdate = false;
            $requestedUserId = $data['user_id'] ?? null;
            if ($requestedUserId) {
                if ($attendance->user_id === null || $attendance->user_id == $requestedUserId) {
                    $allowUserIdUpdate = true;
                } else {
                    // Check for conflict: another attendance for same user and date
                    $conflict = Attendance::where('user_id', $requestedUserId)
                        ->where('date', $attendance->date)
                        ->where('id', '<>', $attendance->id)
                        ->exists();

                    if ($conflict) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Another attendance exists for this user on the same date'
                        ], 409);
                    }

                    $allowUserIdUpdate = true;
                }
            }

            // Calculate working hours when clock_out is provided
            if (isset($data['clock_out']) && $data['clock_out']) {
                $clock_in = $data['clock_in'] ?? $attendance->clock_in;
                $data['working_hours'] = Attendance::calculateWorkingHours($clock_in, $data['clock_out']);
                $data['status'] = 'present';
            }

            // Remove user_id from mass-updated data to handle it explicitly
            if (isset($data['user_id'])) {
                unset($data['user_id']);
            }

            $attendance->update($data);

            if ($allowUserIdUpdate && $requestedUserId) {
                $attendance->user_id = $requestedUserId;
                $attendance->save();
            }

            Log::info('Attendance updated', [
                // 'user_id' => Auth::id(),
                'attendance_id' => $attendance->id,
                'updated_fields' => array_keys($data),
                'working_hours' => $attendance->working_hours,
                'status' => $attendance->status,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendance updated successfully',
                'data' => $attendance->load('employee', 'user')
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update attendance', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update attendance',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Clock out the current user's active attendance for today.
     */
    public function clockOut(Request $request)
    {
        try {
            $user = Auth::user();

            $attendance = Attendance::where('user_id', $user->id)
                ->whereDate('date', now()->toDateString())
                ->whereNull('clock_out')
                ->latest()
                ->first();

            if (!$attendance) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active attendance found for today'
                ], 404);
            }

            $attendance->clock_out = $request->input('clock_out', now()->format('H:i:s'));

            if ($attendance->clock_in) {
                $attendance->working_hours = Attendance::calculateWorkingHours($attendance->clock_in, $attendance->clock_out);
                $attendance->status = 'present';
            }

            $attendance->save();

            Log::info('Attendance clocked out', [
                'user_id' => Auth::id(),
                'attendance_id' => $attendance->id,
                'clock_out' => $attendance->clock_out,
                'working_hours' => $attendance->working_hours,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Clocked out successfully',
                'data' => $attendance->load('employee', 'user')
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to clock out attendance', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to clock out attendance',
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
            $attendance = Attendance::find($id);

            if (!$attendance) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Attendance not found'
                ], 404);
            }

            $attendance->delete();

            Log::info('Attendance deleted', [
                'user_id' => Auth::id(),
                'attendance_id' => $id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Attendance deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to delete attendance', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete attendance',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Daily Attendance Report
     */
    public function dailyReport(Request $request)  // Changed to Request
    {
        try {
            $date = $request->input('date', Carbon::today()->toDateString());
            
            // Validate date
            if (!strtotime($date)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid date format. Use YYYY-MM-DD'
                ], 422);
            }
            
            // Get all users with their employees
            $users = User::with('employee.department')->get();
            
            // Get attendance for the specific date
            $attendances = Attendance::whereDate('date', $date)
                ->get()
                ->keyBy('user_id');
            
            $reportData = [];
            $presentCount = 0;
            $absentCount = 0;
            $lateCount = 0;
            $onTimeCount = 0;
            
            foreach ($users as $user) {
                $attendance = $attendances->get($user->id);
                
                $status = 'absent';
                $clock_in = null;
                $clock_out = null;
                $working_hours = 0;
                $is_late = false;
                $is_on_time = false;
                
                if ($attendance) {
                    $status = $attendance->status ?? 'present';
                    $clock_in = $attendance->clock_in;
                    $clock_out = $attendance->clock_out;
                    $working_hours = $attendance->working_hours;
                    
                    // Check if employee was late (assuming 9:00 AM is start time)
                    if ($clock_in) {
                        $clockInTime = Carbon::createFromFormat('H:i:s', $clock_in);
                        $officeStartTime = Carbon::createFromTime(9, 0, 0);
                        
                        if ($clockInTime > $officeStartTime) {
                            $is_late = true;
                            $lateCount++;
                        } else {
                            $is_on_time = true;
                            $onTimeCount++;
                        }
                    }
                    
                    $presentCount++;
                } else {
                    $absentCount++;
                }
                
                $reportData[] = [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'employee' => $user->employee ? [
                            'id' => $user->employee->id,
                            'full_name' => $user->employee->full_name,
                            'employee_code' => $user->employee->employee_code,
                            'department' => $user->employee->department,
                        ] : null,
                    ],
                    'attendance' => [
                        'date' => $date,
                        'status' => $status,
                        'clock_in' => $clock_in,
                        'clock_out' => $clock_out,
                        'working_hours' => $working_hours,
                        'is_late' => $is_late,
                        'is_on_time' => $is_on_time,
                    ]
                ];
            }
            
            // Calculate summary
            $summary = [
                'total_employees' => $users->count(),
                'present' => $presentCount,
                'absent' => $absentCount,
                'attendance_percentage' => $users->count() > 0 
                    ? round(($presentCount / $users->count()) * 100, 2)
                    : 0,
                'on_time' => $onTimeCount,
                'late' => $lateCount,
                'late_percentage' => $presentCount > 0 
                    ? round(($lateCount / $presentCount) * 100, 2)
                    : 0,
            ];
            
            Log::info('Daily attendance report generated', [
                'user_id' => Auth::id(),
                'date' => $date,
                'summary' => $summary
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Daily attendance report retrieved successfully',
                'data' => [
                    'report_type' => 'daily',
                    'date' => $date,
                    'summary' => $summary,
                    'employees' => $reportData,
                ]
            ], 200);
            
        } catch (\Throwable $th) {
            Log::error('Failed to generate daily report', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate daily report: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Weekly Attendance Report
     */
    public function weeklyReport(Request $request)  // Changed to Request
    {
        try {
            $date = $request->input('date', Carbon::today()->toDateString());
            $startOfWeek = Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
            $endOfWeek = Carbon::parse($date)->endOfWeek(Carbon::SUNDAY)->toDateString();
            
            // Get all users
            $users = User::with('employee.department')->get();
            
            // Get all dates in the week
            $weekDates = $this->getDateRange($startOfWeek, $endOfWeek);
            
            // Get all attendance for the week
            $attendances = Attendance::whereBetween('date', [$startOfWeek, $endOfWeek])
                ->get()
                ->groupBy('user_id');
            
            $reportData = [];
            $weeklySummary = [];
            
            foreach ($users as $user) {
                $userAttendances = $attendances->get($user->id) ?? collect();
                $weeklyAttendance = [];
                $presentDays = 0;
                $absentDays = 0;
                $lateDays = 0;
                $totalWorkingHours = 0;
                
                foreach ($weekDates as $weekDate) {
                    $attendance = $userAttendances->firstWhere('date', $weekDate);
                    
                    if ($attendance) {
                        $status = $attendance->status ?? 'present';
                        $presentDays++;
                        $totalWorkingHours += $attendance->working_hours ?? 0;
                        
                        // Check if late
                        if ($attendance->clock_in) {
                            $clockInTime = Carbon::createFromFormat('H:i:s', $attendance->clock_in);
                            if ($clockInTime > Carbon::createFromTime(9, 0, 0)) {
                                $lateDays++;
                            }
                        }
                        
                        $weeklyAttendance[] = [
                            'date' => $weekDate,
                            'status' => $status,
                            'clock_in' => $attendance->clock_in,
                            'clock_out' => $attendance->clock_out,
                            'working_hours' => $attendance->working_hours,
                        ];
                    } else {
                        $absentDays++;
                        $weeklyAttendance[] = [
                            'date' => $weekDate,
                            'status' => 'absent',
                            'clock_in' => null,
                            'clock_out' => null,
                            'working_hours' => 0,
                        ];
                    }
                }
                
                $attendancePercentage = count($weekDates) > 0 
                    ? round(($presentDays / count($weekDates)) * 100, 2)
                    : 0;
                
                $reportData[] = [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'employee' => $user->employee ? [
                            'id' => $user->employee->id,
                            'full_name' => $user->employee->full_name,
                            'employee_code' => $user->employee->employee_code,
                            'department' => $user->employee->department,
                        ] : null,
                    ],
                    'weekly_attendance' => $weeklyAttendance,
                    'summary' => [
                        'present_days' => $presentDays,
                        'absent_days' => $absentDays,
                        'late_days' => $lateDays,
                        'total_working_hours' => round($totalWorkingHours, 2),
                        'attendance_percentage' => $attendancePercentage,
                    ]
                ];
                
                // Accumulate for weekly summary
                if (!isset($weeklySummary[$user->employee?->department_id ?? 'no_department'])) {
                    $weeklySummary[$user->employee?->department_id ?? 'no_department'] = [
                        'department_name' => $user->employee?->department->name ?? 'No Department',
                        'total_employees' => 0,
                        'total_present_days' => 0,
                        'total_absent_days' => 0,
                        'total_working_hours' => 0,
                    ];
                }
                
                $weeklySummary[$user->employee?->department_id ?? 'no_department']['total_employees']++;
                $weeklySummary[$user->employee?->department_id ?? 'no_department']['total_present_days'] += $presentDays;
                $weeklySummary[$user->employee?->department_id ?? 'no_department']['total_absent_days'] += $absentDays;
                $weeklySummary[$user->employee?->department_id ?? 'no_department']['total_working_hours'] += $totalWorkingHours;
            }
            
            // Calculate department averages
            foreach ($weeklySummary as &$dept) {
                $totalDays = $dept['total_employees'] * count($weekDates);
                $dept['attendance_percentage'] = $totalDays > 0 
                    ? round(($dept['total_present_days'] / $totalDays) * 100, 2)
                    : 0;
                $dept['avg_working_hours'] = $dept['total_employees'] > 0
                    ? round($dept['total_working_hours'] / $dept['total_employees'], 2)
                    : 0;
            }
            
            Log::info('Weekly attendance report generated', [
                'user_id' => Auth::id(),
                'week' => $startOfWeek . ' to ' . $endOfWeek
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Weekly attendance report retrieved successfully',
                'data' => [
                    'report_type' => 'weekly',
                    'week_start' => $startOfWeek,
                    'week_end' => $endOfWeek,
                    'total_days' => count($weekDates),
                    'department_summary' => array_values($weeklySummary),
                    'employees' => $reportData,
                ]
            ], 200);
            
        } catch (\Throwable $th) {
            Log::error('Failed to generate weekly report', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate weekly report: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Monthly Attendance Report
     */
    public function monthlyReport(Request $request)  // Changed to Request
    {
        try {
            $month = $request->input('month', Carbon::now()->format('Y-m'));
            $year = $request->input('year', Carbon::now()->year);
            
            // Parse month parameter (format: YYYY-MM)
            if (strpos($month, '-') !== false) {
                $parts = explode('-', $month);
                $year = $parts[0];
                $month = $parts[1];
            }
            
            $startOfMonth = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
            $endOfMonth = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
            
            // Get all users
            $users = User::with('employee.department')->get();
            
            // Get all dates in the month
            $monthDates = $this->getDateRange($startOfMonth, $endOfMonth);
            
            // Get all attendance for the month
            $attendances = Attendance::whereBetween('date', [$startOfMonth, $endOfMonth])
                ->get()
                ->groupBy('user_id');
            
            $reportData = [];
            $monthlySummary = [];
            
            foreach ($users as $user) {
                $userAttendances = $attendances->get($user->id) ?? collect();
                $monthlyAttendance = [];
                $presentDays = 0;
                $absentDays = 0;
                $lateDays = 0;
                $leaveDays = 0;
                $halfDays = 0;
                $totalWorkingHours = 0;
                
                foreach ($monthDates as $date) {
                    $attendance = $userAttendances->firstWhere('date', $date);
                    
                    if ($attendance) {
                        $status = $attendance->status ?? 'present';
                        
                        switch ($status) {
                            case 'present':
                                $presentDays++;
                                break;
                            case 'leave':
                                $leaveDays++;
                                break;
                            case 'half_day':
                                $halfDays++;
                                break;
                        }
                        
                        $totalWorkingHours += $attendance->working_hours ?? 0;
                        
                        // Check if late
                        if ($attendance->clock_in) {
                            $clockInTime = Carbon::createFromFormat('H:i:s', $attendance->clock_in);
                            if ($clockInTime > Carbon::createFromTime(9, 0, 0)) {
                                $lateDays++;
                            }
                        }
                        
                        $monthlyAttendance[] = [
                            'date' => $date,
                            'status' => $status,
                            'clock_in' => $attendance->clock_in,
                            'clock_out' => $attendance->clock_out,
                            'working_hours' => $attendance->working_hours,
                        ];
                    } else {
                        $absentDays++;
                        $monthlyAttendance[] = [
                            'date' => $date,
                            'status' => 'absent',
                            'clock_in' => null,
                            'clock_out' => null,
                            'working_hours' => 0,
                        ];
                    }
                }
                
                $totalPresentEquivalents = $presentDays + ($halfDays * 0.5);
                $attendancePercentage = count($monthDates) > 0 
                    ? round(($totalPresentEquivalents / count($monthDates)) * 100, 2)
                    : 0;
                
                $reportData[] = [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'employee' => $user->employee ? [
                            'id' => $user->employee->id,
                            'full_name' => $user->employee->full_name,
                            'employee_code' => $user->employee->employee_code,
                            'department' => $user->employee->department,
                        ] : null,
                    ],
                    'monthly_attendance' => $monthlyAttendance,
                    'summary' => [
                        'present_days' => $presentDays,
                        'absent_days' => $absentDays,
                        'leave_days' => $leaveDays,
                        'half_days' => $halfDays,
                        'late_days' => $lateDays,
                        'total_working_hours' => round($totalWorkingHours, 2),
                        'attendance_percentage' => $attendancePercentage,
                    ]
                ];
                
                // Accumulate for monthly summary
                $deptKey = $user->employee?->department_id ?? 'no_department';
                if (!isset($monthlySummary[$deptKey])) {
                    $monthlySummary[$deptKey] = [
                        'department_name' => $user->employee?->department->name ?? 'No Department',
                        'total_employees' => 0,
                        'total_present_days' => 0,
                        'total_absent_days' => 0,
                        'total_leave_days' => 0,
                        'total_half_days' => 0,
                        'total_late_days' => 0,
                        'total_working_hours' => 0,
                    ];
                }
                
                $monthlySummary[$deptKey]['total_employees']++;
                $monthlySummary[$deptKey]['total_present_days'] += $presentDays;
                $monthlySummary[$deptKey]['total_absent_days'] += $absentDays;
                $monthlySummary[$deptKey]['total_leave_days'] += $leaveDays;
                $monthlySummary[$deptKey]['total_half_days'] += $halfDays;
                $monthlySummary[$deptKey]['total_late_days'] += $lateDays;
                $monthlySummary[$deptKey]['total_working_hours'] += $totalWorkingHours;
            }
            
            // Calculate department averages
            foreach ($monthlySummary as &$dept) {
                $totalDays = $dept['total_employees'] * count($monthDates);
                $totalPresentEquivalents = $dept['total_present_days'] + ($dept['total_half_days'] * 0.5);
                $dept['attendance_percentage'] = $totalDays > 0 
                    ? round(($totalPresentEquivalents / $totalDays) * 100, 2)
                    : 0;
                $dept['avg_working_hours'] = $dept['total_employees'] > 0
                    ? round($dept['total_working_hours'] / $dept['total_employees'], 2)
                    : 0;
            }
            
            Log::info('Monthly attendance report generated', [
                'user_id' => Auth::id(),
                'month' => $startOfMonth . ' to ' . $endOfMonth
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Monthly attendance report retrieved successfully',
                'data' => [
                    'report_type' => 'monthly',
                    'month' => $month,
                    'year' => $year,
                    'month_name' => Carbon::createFromDate($year, $month, 1)->format('F Y'),
                    'start_date' => $startOfMonth,
                    'end_date' => $endOfMonth,
                    'total_days' => count($monthDates),
                    'department_summary' => array_values($monthlySummary),
                    'employees' => $reportData,
                ]
            ], 200);
            
        } catch (\Throwable $th) {
            Log::error('Failed to generate monthly report', [
                'user_id' => Auth::id(),
                'error' => $th->getMessage()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate monthly report: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get all dates between from_date and to_date
     */
    private function getDateRange($fromDate, $toDate): array
    {
        $dates = [];
        $current = strtotime($fromDate);
        $end = strtotime($toDate);
        
        while ($current <= $end) {
            $dates[] = date('Y-m-d', $current);
            $current = strtotime('+1 day', $current);
        }
        
        return $dates;
    }
}