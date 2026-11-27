<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateLeaveRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $leaveTypeId = $this->input('leave_type_id');
        $isMedical = false;
        
        if ($leaveTypeId) {
            $leaveType = \App\Models\LeaveType::find($leaveTypeId);
            if ($leaveType && (
                $leaveType->requires_medical_certificate ||
                stripos($leaveType->name, 'medical') !== false ||
                strtolower((string) $leaveType->code) === 'ml'
            )) {
                $isMedical = true;
            }
        }

        $isShortLeave = false;
        if ($leaveTypeId) {
            $leaveType = $leaveType ?? \App\Models\LeaveType::find($leaveTypeId);
            if ($leaveType && (stripos((string) $leaveType->name, 'short') !== false || strtolower((string) $leaveType->code) === 'sl')) {
                $isShortLeave = true;
            }
        }

        $fromDateRule = 'required|date';
        // "only medical leaves can apply on same day. others should apply before" (short leave allowed same day)
        if (!$isMedical && !$isShortLeave) {
            $fromDateRule .= '|after:today';
        }

        $toDateRule = $isShortLeave
            ? 'required|date|same:from_date'
            : 'required|date|after_or_equal:from_date';

        return [
            'employee_id' => 'nullable|exists:employees,id',
            'user_id' => 'sometimes|exists:users,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'from_date' => $fromDateRule,
            'to_date' => $toDateRule,
            'from_time' => $isShortLeave ? 'required|date_format:H:i' : 'nullable|date_format:H:i',
            'to_time' => $isShortLeave ? 'required|date_format:H:i|after:from_time' : 'nullable|date_format:H:i|after:from_time',
            'reason' => 'nullable|string|max:1000',
            'medical_certificate' => 'nullable|file|mimes:pdf,jpg,jpeg,png',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator)
    {
        $validator->after(function ($validator) {
            // Get employee ID and user ID
            $employeeId = $this->input('employee_id');
            $userId = $this->input('user_id') ?? \Illuminate\Support\Facades\Auth::id();

            if (!$employeeId && $userId) {
                $user = \App\Models\User::with('employee')->find($userId);
                $employeeId = $user?->employee?->id;
            }

            $fromDate = $this->input('from_date');
            $toDate = $this->input('to_date');
            $fromTime = $this->input('from_time');
            $toTime = $this->input('to_time');
            $leaveTypeId = $this->input('leave_type_id');

            $isShortLeave = false;
            if ($leaveTypeId) {
                $leaveType = \App\Models\LeaveType::find($leaveTypeId);
                $isShortLeave = $leaveType && (stripos((string) $leaveType->name, 'short') !== false || strtolower((string) $leaveType->code) === 'sl');
            }

            // Short leave: enforce 1.5 hours duration and same-day partial leave
            if ($isShortLeave && $fromTime && $toTime) {
                try {
                    [$fh, $fm] = array_map('intval', explode(':', substr((string) $fromTime, 0, 5)));
                    [$th, $tm] = array_map('intval', explode(':', substr((string) $toTime, 0, 5)));
                    $minutes = (($th * 60) + $tm) - (($fh * 60) + $fm);
                    if ($minutes !== 90) {
                        $validator->errors()->add('to_time', 'Short leave duration must be exactly 1 hour and 30 minutes.');
                    }
                } catch (\Throwable $e) {
                    // format validation handled by rules
                }
            }

            if ($isShortLeave && ($employeeId || $userId) && $fromDate) {
                try {
                    $monthDate = \Carbon\Carbon::parse($fromDate);
                    $countQuery = \App\Models\Leave::whereIn('status', ['pending', 'approved'])
                        ->whereYear('from_date', $monthDate->year)
                        ->whereMonth('from_date', $monthDate->month)
                        ->whereHas('leaveType', function ($q) {
                            $q->whereRaw("UPPER(code) = 'SL'")
                              ->orWhere('name', 'like', '%short%');
                        });

                    if ($employeeId) {
                        $countQuery->where('employee_id', $employeeId);
                    } else {
                        $countQuery->where('user_id', $userId);
                    }

                    if ($countQuery->count() >= 2) {
                        $validator->errors()->add('from_date', 'You can apply for only 2 short leaves per month.');
                    }
                } catch (\Throwable $e) {
                    // ignore parsing issues; date format is validated by rules
                }
            }

            if (($employeeId || $userId) && $fromDate && $toDate) {
                // Check if any leave overlaps for this employee/user
                $query = \App\Models\Leave::whereIn('status', ['pending', 'approved']);

                if ($employeeId) {
                    $query->where('employee_id', $employeeId);
                } else {
                    $query->where('user_id', $userId);
                }

                if ($isShortLeave && $fromDate === $toDate && $fromTime && $toTime) {
                    // Time-aware overlap on same day (allow multiple short leaves if times don't overlap)
                    $overlaps = $query->whereDate('from_date', '<=', $fromDate)
                        ->whereDate('to_date', '>=', $toDate)
                        ->where(function ($q) use ($fromTime, $toTime) {
                            // Existing full-day leave blocks the day
                            $q->whereNull('from_time')->whereNull('to_time')
                              // Existing timed leave overlaps requested range
                              ->orWhere(function ($q2) use ($fromTime, $toTime) {
                                  $q2->whereNotNull('from_time')->whereNotNull('to_time')
                                     ->where('from_time', '<', $toTime)
                                     ->where('to_time', '>', $fromTime);
                              });
                        })
                        ->exists();
                } else {
                    $overlaps = $query->where(function ($query) use ($fromDate, $toDate) {
                            $query->whereBetween('from_date', [$fromDate, $toDate])
                                  ->orWhereBetween('to_date', [$fromDate, $toDate])
                                  ->orWhere(function ($q) use ($fromDate, $toDate) {
                                      $q->where('from_date', '<=', $fromDate)
                                        ->where('to_date', '>=', $toDate);
                                  });
                        })
                        ->exists();
                }

                if ($overlaps) {
                    $validator->errors()->add('from_date', 'You have already applied for leave during these dates/times.');
                }
            }
        });
    }

    protected function failedValidation(Validator $validator)
    {
        $errorMessages = $validator->errors();

        $fieldErrors = collect($errorMessages->getMessages())->map(function ($messages, $field) {
            return [
                'field' => $field,
                'messages' => $messages,
            ];
        })->values();

        $message = $fieldErrors->count() > 1
            ? 'There are multiple validation errors. Please review the form and correct the issues.'
            : ($fieldErrors->first()['messages'][0] ?? 'Validation failed.');

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }
}
