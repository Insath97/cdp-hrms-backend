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
            'profile_image' => 'nullable|string|max:255',
            'department_id' => 'sometimes|exists:departments,id',
            'employee_id' => 'nullable|string|max:50|unique:employees,employee_id,' . $id,
            'id_type' => 'sometimes|in:nic,passport,driving_license,other',
            'id_number' => 'sometimes|string|max:50|unique:employees,id_number,' . $id,
            'date_of_birth' => 'sometimes|date',
            'email' => 'sometimes|email|max:255|unique:employees,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'sometimes|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'phone_primary' => 'sometimes|string|max:20',
            'phone_secondary' => 'nullable|string|max:20',
            'have_whatsapp' => 'sometimes|boolean',
            'whatsapp_number' => 'nullable|string|max:20',
            'joined_at' => 'sometimes|date',
            'left_at' => 'nullable|date',
            'basic_salary' => 'sometimes|numeric|min:0',
            'is_active' => 'sometimes|boolean',
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
            ? 'There are multiple validation errors. Please review the form and correct the issues.'
            : 'There is an issue with the input for ' . $fieldErrors->first()['field'] . '.';

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }
}
