<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateEmployeeRequest extends FormRequest
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
        $id = $this->route('employee');
        return [
            'f_name' => 'sometimes|string|max:255',
            'l_name' => 'sometimes|string|max:255',
            'full_name' => 'sometimes|string|max:255',
            'name_with_initials' => 'sometimes|string|max:255',
            'employee_code' => 'sometimes|string|max:50|unique:employees,employee_code,' . $id,
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'reporting_manager_id' => 'nullable|exists:employees,id',
            'province_id' => 'nullable|exists:provinces,id',
            'region_id' => 'nullable|exists:regions,id',
            'zonal_id' => 'nullable|exists:zonals,id',
            'branch_id' => 'nullable|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'sometimes|exists:designations,id',
            'employee_type' => 'sometimes|nullable|in:permanent,contract,internship,probation,non_permanent,solo',
            'id_type' => 'sometimes|in:nic,passport,driving_license,other',
            'id_number' => 'sometimes|string|max:50|unique:employees,id_number,' . $id,
            'date_of_birth' => 'sometimes|date',
            'email' => 'sometimes|nullable|email|max:255|unique:employees,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'address_line_1' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'sometimes|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'phone_primary' => 'sometimes|string|max:20',
            'phone_secondary' => 'nullable|string|max:20',
            'have_whatsapp' => 'sometimes|boolean',
            'whatsapp_number' => 'nullable|string|max:20',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'extended_until' => 'nullable|date',
            'extension_reason' => 'nullable|string|max:1000',
            'joined_at' => 'sometimes|nullable|date',
            'left_at' => 'nullable|date',
            'termination_reason' => 'nullable|string',
            'permanent_at' => 'nullable|date',
            'employment_status' => 'sometimes|in:active,inactive,terminated',
            'basic_salary' => 'sometimes|numeric|min:0',
            'bank_name' => 'nullable|string|max:255',
            'bank_branch' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function withValidator($validator)
    {
        $validator->sometimes('email', 'email', function ($input) {
            return !is_null($input->email) && $input->email !== '';
        });
    }

    /**
     * Convert empty string values to null for nullable fields before validation runs.
     * This prevents format validators (email, date, exists) from failing on empty strings.
     */
    protected function prepareForValidation(): void
    {
        $nullableFields = [
            'email', 'end_date', 'start_date', 'left_at', 'joined_at', 'date_of_birth',
            'extended_until', 'permanent_at', 'reporting_manager_id', 'province_id',
            'region_id', 'zonal_id', 'branch_id', 'department_id',
            'phone', 'phone_secondary', 'whatsapp_number', 'address_line_1',
            'city', 'state', 'postal_code', 'bank_name', 'bank_branch',
            'account_number', 'extension_reason', 'termination_reason',
            'description', 'employee_type',
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
            : 'There is an issue with the input for ' . $fieldErrors->first()['field'] . '.';

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }
}
