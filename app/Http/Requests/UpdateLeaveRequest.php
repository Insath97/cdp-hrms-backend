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
            'reason' => 'nullable|string|max:1000',
            'medical_certificate' => 'nullable|file|mimes:pdf,jpg,jpeg,png',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator)
    {
        $validator->after(function ($validator) {
            $leaveId = $this->route('leave') ?? $this->route('id');
            // Support both parameter names 'leave' or 'id'
            if (!$leaveId) {
                // we have to guess it from the URL
                $routeParams = $this->route()->parameters();
                $leaveId = reset($routeParams);
            }

            if ($leaveId) {
                $leave = \App\Models\Leave::find($leaveId);
                
                if ($leave) {
                    $employeeId = $this->input('employee_id') ?? $leave->employee_id;
                    $userId = $this->input('user_id') ?? $leave->user_id;
                    $fromDate = $this->input('from_date') ?? ($leave->from_date instanceof \Carbon\Carbon ? $leave->from_date->format('Y-m-d') : $leave->from_date);
                    $toDate = $this->input('to_date') ?? ($leave->to_date instanceof \Carbon\Carbon ? $leave->to_date->format('Y-m-d') : $leave->to_date);

                    if (($employeeId || $userId) && $fromDate && $toDate) {
                        // Check for overlaps excluding the current leave
                        $query = \App\Models\Leave::where('id', '!=', $leaveId)
                            ->whereIn('status', ['pending', 'approved']);
                            
                        if ($employeeId) {
                            $query->where('employee_id', $employeeId);
                        } else {
                            $query->where('user_id', $userId);
                        }
                        
                        $overlaps = $query->where(function ($query) use ($fromDate, $toDate) {
                                $query->whereBetween('from_date', [$fromDate, $toDate])
                                      ->orWhereBetween('to_date', [$fromDate, $toDate])
                                      ->orWhere(function ($q) use ($fromDate, $toDate) {
                                          $q->where('from_date', '<=', $fromDate)
                                            ->where('to_date', '>=', $toDate);
                                      });
                            })
                            ->exists();

                        if ($overlaps) {
                            $validator->errors()->add('from_date', 'You have already applied for leave during these dates.');
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
