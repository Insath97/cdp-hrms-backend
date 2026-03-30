<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateLeaveTypeRequest extends FormRequest
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
        $id = $this->route('leave_type');
        return [
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50|unique:leave_types,code,' . $id,
            'calculation_unit' => 'sometimes|in:days,hours',
            'default_allocation' => 'sometimes|numeric|min:0',
            'color_code' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'is_paid' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'is_pregnancy_related' => 'sometimes|boolean',
            'pregnancy_weeks_required' => 'nullable|required_if:is_pregnancy_related,true|integer|min:0',
            'pre_delivery_weeks' => 'nullable|required_if:is_pregnancy_related,true|integer|min:0',
            'post_delivery_weeks' => 'nullable|required_if:is_pregnancy_related,true|integer|min:0',
            'requires_medical_certificate' => 'sometimes|boolean',
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
