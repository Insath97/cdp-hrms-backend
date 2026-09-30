<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdatePurposeCodeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Resolve the current purpose code id regardless of whether route model
     * binding has already turned the route parameter into a model.
     */
    private function purposeCodeId(): ?int
    {
        $purposeCode = $this->route('purpose_code');

        if ($purposeCode instanceof PurposeCode) {
            return $purposeCode->id;
        }

        return is_numeric($purposeCode) ? (int) $purposeCode : null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $id = $this->purposeCodeId();

        return [
            'code' => 'sometimes|string|size:6|regex:/^\d{6}$/|unique:purpose_codes,code,' . $id . ',id',
            'description' => 'sometimes|string|max:255',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'The purpose code must be exactly 6 digits.',
            'code.size' => 'The purpose code must be exactly 6 digits.',
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
