<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateLeaveRequest extends FormRequest
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
        return [
            'employee_id' => 'sometimes|nullable|exists:employees,id',
            'leave_type_id' => 'sometimes|exists:leave_types,id',
            'from_date' => 'sometimes|date|after_or_equal:today',
            'to_date' => 'sometimes|date|after_or_equal:from_date',
            'from_time' => 'nullable|date_format:H:i',
            'to_time' => 'nullable|date_format:H:i|after:from_time',
            'reason' => 'nullable|string|max:1000',
            'medical_certificate' => 'nullable|file|mimes:pdf,jpg,jpeg,png',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator)
    {
        $validator->after(function ($validator) {
            $leaveId = $this->route('leave') ?? $this->route('id');
            if (!$leaveId) {
                $routeParams = $this->route()->parameters();
                $leaveId = reset($routeParams);
            }

            if ($leaveId) {
                $leave = \App\Models\Leave::find($leaveId);

                if ($leave) {
                    $employeeId = $this->input('employee_id') ?? $leave->employee_id;
                    $userId = $this->input('user_id') ?? $leave->user_id;
                    $leaveTypeId = $this->input('leave_type_id') ?? $leave->leave_type_id;
                    $fromDate = $this->input('from_date') ?? ($leave->from_date instanceof \Carbon\Carbon ? $leave->from_date->format('Y-m-d') : $leave->from_date);
                    $toDate = $this->input('to_date') ?? ($leave->to_date instanceof \Carbon\Carbon ? $leave->to_date->format('Y-m-d') : $leave->to_date);
                    $fromTime = $this->has('from_time') ? $this->input('from_time') : $leave->from_time;
                    $toTime = $this->has('to_time') ? $this->input('to_time') : $leave->to_time;

                    $isShortLeave = false;
                    if ($leaveTypeId) {
                        $leaveType = \App\Models\LeaveType::find($leaveTypeId);
                        $isShortLeave = $leaveType && (stripos((string) $leaveType->name, 'short') !== false || strtolower((string) $leaveType->code) === 'sl');
                    }

                    if ($isShortLeave) {
                        if ($fromDate !== $toDate) {
                            $validator->errors()->add('to_date', 'Short leave must be applied for a single day.');
                        }
                        if (!$fromTime || !$toTime) {
                            $validator->errors()->add('from_time', 'From time and to time are required for short leave.');
                        } elseif ($fromTime && $toTime) {
                            try {
                                [$fh, $fm] = array_map('intval', explode(':', substr((string) $fromTime, 0, 5)));
                                [$th, $tm] = array_map('intval', explode(':', substr((string) $toTime, 0, 5)));
                                $minutes = (($th * 60) + $tm) - (($fh * 60) + $fm);
                                if ($minutes !== 90) {
                                    $validator->errors()->add('to_time', 'Short leave duration must be exactly 1 hour and 30 minutes.');
                                }
                            } catch (\Throwable $e) {}
                        }
                    }

                    if ($isShortLeave && ($employeeId || $userId) && $fromDate) {
                        try {
                            $monthDate = \Carbon\Carbon::parse($fromDate);
                            $countQuery = \App\Models\Leave::where('id', '!=', $leaveId)
                                ->whereIn('status', ['pending', 'approved'])
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
                            // ignore parsing issues
                        }
                    }

                    if (($employeeId || $userId) && $fromDate && $toDate) {
                        $query = \App\Models\Leave::where('id', '!=', $leaveId)
                            ->whereIn('status', ['pending', 'approved']);

                        if ($employeeId) {
                            $query->where('employee_id', $employeeId);
                        } else {
                            $query->where('user_id', $userId);
                        }

                        $fromTimeNorm = $fromTime ? substr((string) $fromTime, 0, 5) : null;
                        $toTimeNorm = $toTime ? substr((string) $toTime, 0, 5) : null;

                        if ($isShortLeave && $fromDate === $toDate && $fromTimeNorm && $toTimeNorm) {
                            $overlaps = $query->whereDate('from_date', '<=', $fromDate)
                                ->whereDate('to_date', '>=', $toDate)
                                ->where(function ($q) use ($fromTimeNorm, $toTimeNorm) {
                                    $q->whereNull('from_time')->whereNull('to_time')
                                      ->orWhere(function ($q2) use ($fromTimeNorm, $toTimeNorm) {
                                          $q2->whereNotNull('from_time')->whereNotNull('to_time')
                                             ->where('from_time', '<', $toTimeNorm)
                                             ->where('to_time', '>', $fromTimeNorm);
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
