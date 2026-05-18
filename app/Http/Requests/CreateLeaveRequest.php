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
                strtolower($leaveType->code) === 'ml'
            )) {
                $isMedical = true;
            }
        }

        $fromDateRule = 'required|date';
        
        // "only medical leaves can apply on same day. others should apply before"
        if (!$isMedical) {
            $fromDateRule .= '|after:today';
        }

        return [
            'employee_id' => 'nullable|exists:employees,id',
            'user_id' => 'sometimes|exists:users,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'from_date' => $fromDateRule,
            'to_date' => 'required|date|after_or_equal:from_date',
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

            if (($employeeId || $userId) && $fromDate && $toDate) {
                // Check if any leave overlaps for this employee/user
                $query = \App\Models\Leave::whereIn('status', ['pending', 'approved']);
                
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
