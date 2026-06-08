<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateLetterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('startDate') && !$this->has('start_date')) {
            $this->merge(['start_date' => $this->startDate]);
        } elseif ($this->has('startDate') && $this->start_date === null) {
            $this->merge(['start_date' => $this->startDate]);
        }

        if ($this->has('endDate') && !$this->has('end_date')) {
            $this->merge(['end_date' => $this->endDate]);
        } elseif ($this->has('endDate') && $this->end_date === null) {
            $this->merge(['end_date' => $this->endDate]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ref_number' => 'required|string|unique:letters,ref_number',
            'title' => 'required|string|max:255',
            'letter_type' => 'required|string|in:offer,contract,promotion,increment,termination,experience',
            'employee_name' => 'required|string|max:255',
            'nic_number' => 'required_if:letter_type,contract|nullable|string|max:255',
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'department_id' => 'required|exists:departments,id',
            'designation_id' => 'required|exists:designations,id',
            'branch_id' => 'required_if:letter_type,contract|nullable|exists:branches,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'date' => 'required|date',
            'signed_by_name' => 'required|string|max:255',
            'signed_by_designation' => 'required|string|max:255',
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
