<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class BackdatedClockRequest extends FormRequest
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
            'user_id' => 'required_without:employee_code|nullable|exists:users,id',
            'employee_code' => 'required_without:user_id|nullable|string|max:50',
            'date' => 'required|date',
            'clock_in' => 'required_without:clock_out|nullable|date_format:H:i:s',
            'clock_out' => 'required_without:clock_in|nullable|date_format:H:i:s',
            'status' => 'nullable|in:present,absent,half_day,leave,late',
            'in_latitude' => 'nullable|numeric|between:-90,90',
            'in_longitude' => 'nullable|numeric|between:-180,180',
            'out_latitude' => 'nullable|numeric|between:-90,90',
            'out_longitude' => 'nullable|numeric|between:-180,180',
            'in_geofence_name' => 'nullable|string|max:255',
            'out_geofence_name' => 'nullable|string|max:255',
            'in_ipAddress' => 'nullable|string|max:45',
            'out_ipAddress' => 'nullable|string|max:45',
            'created_at' => 'nullable|date_format:Y-m-d H:i:s',
            'updated_at' => 'nullable|date_format:Y-m-d H:i:s',
            'reason' => 'nullable|string|max:500',
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
