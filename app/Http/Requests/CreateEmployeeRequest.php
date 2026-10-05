<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\Bank;
use App\Services\BankAccountFormatService;
use App\Support\TextNormalizer;

class CreateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'f_name' => 'required|string|max:255',
            'l_name' => 'required|string|max:255',
            'full_name' => 'required|string|max:255',
            'name_with_initials' => 'required|string|max:255',
            'initials' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z]+(\s+[a-zA-Z]+)*$/'],
            'surname' => 'nullable|string|max:255',
            'employee_code' => 'nullable|sometimes|string|unique:employees,employee_code|max:50',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'province_id' => 'nullable|exists:provinces,id',
            'region_id' => 'nullable|exists:regions,id',
            'zonal_id' => 'nullable|exists:zonals,id',
            'branch_id' => 'nullable|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'required|exists:designations,id',
            'employee_type' => ['required', 'in:permanent,contract,internship,probation,non_permanent,solo'],
            'id_type' => 'required|in:nic,passport,driving_license,other',
            'id_number' => 'required|string|unique:employees,id_number|max:50',
            'date_of_birth' => 'required|date',
            'email' => 'sometimes|nullable|email|unique:employees,email|max:255', // Fixed: Added 'sometimes'
            'phone' => 'nullable|string|max:20',
            'address_line_1' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'sometimes|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'phone_primary' => 'required|string|max:20',
            'phone_secondary' => 'nullable|string|max:20',
            'have_whatsapp' => 'sometimes|boolean',
            'whatsapp_number' => 'nullable|string|max:20',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'extended_until' => 'nullable|date',
            'extension_reason' => 'nullable|string|max:1000',
            'joined_at' => 'sometimes|date',
            'employment_status' => 'sometimes|in:active,inactive,terminated',
            'basic_salary' => 'sometimes|nullable|numeric|min:0',
            'travel_reimbursement' => 'sometimes|nullable|numeric|min:0',
            'vehicle_rental' => 'sometimes|nullable|numeric|min:0',
            'performance_allowance' => 'sometimes|nullable|numeric|min:0',
            'incentive' => 'sometimes|nullable|numeric|min:0',
            'position_allowance' => 'sometimes|nullable|numeric|min:0',
            'mobile_payment' => 'sometimes|nullable|numeric|min:0',
            'monthly_target' => 'sometimes|nullable|numeric|min:0',
            'bank_name' => 'nullable|string|max:255',
            'bank_id' => 'nullable|integer|exists:banks,id',
            'bank_branch' => 'nullable|string|max:255',
            'account_number' => ['nullable', 'string', 'max:50', function ($attribute, $value, $fail) {
                if ($value === null || trim((string) $value) === '') {
                    return;
                }
                $bank = $this->input('bank_id')
                    ? Bank::find($this->input('bank_id'))
                    : null;
                [$valid, $message] = BankAccountFormatService::validateAccountNumber((string) $value, $bank);
                if (!$valid) {
                    $fail($message);
                }
            }],
            'description' => 'nullable|string',
            'username' => 'required|string|max:255|unique:users,username',
            'password' => 'required|string|min:8',
            'user_type' => 'sometimes|in:admin,staff',
            'role' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'geofence_ids' => 'sometimes|array',
            'geofence_ids.*' => 'exists:geofences,id',
        ];
    }

    /**
     * Prepare the data for validation
     */
    protected function prepareForValidation(): void
    {
        // Convert empty strings to null for nullable fields
        $nullableFields = [
            'email', 'phone', 'phone_secondary', 'whatsapp_number',
            'address_line_1', 'city', 'state', 'postal_code',
            'bank_name', 'bank_branch', 'account_number', 'description', 'bank_id',
            'initials', 'surname'
        ];

        $merge = [];
        foreach ($nullableFields as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }

        // Normalize exotic Unicode fonts (copy-paste issues) for text fields
        $normalizeFields = [
            'f_name', 'l_name', 'full_name', 'name_with_initials', 'initials', 'surname', 'email',
            'address_line_1', 'city', 'state', 'country',
            'bank_name', 'bank_branch', 'description', 'extension_reason',
        ];

        $merge = [];
        foreach ($normalizeFields as $field) {
            $value = $this->input($field);
            if (is_string($value) && $value !== '') {
                $normalized = TextNormalizer::normalize($value);
                if ($normalized !== $value) {
                    $merge[$field] = $normalized;
                }
            }
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    public function messages(): array
    {
        return [
            'initials.regex' => 'Initials may only contain letters and spaces, e.g. "K D S".',
        ];
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
            ? 'Please fix the highlighted fields and try again. ('. $fieldErrors->first()['messages'][0] .')'
            : $fieldErrors->first()['messages'][0];

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }
}
