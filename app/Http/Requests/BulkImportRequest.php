<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Add proper authorization logic here if needed
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:csv,txt|max:5120', // 5MB limit
            'table' => [
                'required',
                'string',
                Rule::in([
                    'countries',
                    'provinces',
                    'zonals',
                    'regions',
                    'branches',
                    'departments',
                    'designations',
                    'employees'
                ])
            ],
        ];
    }

    /**
     * Get custom error messages for validation.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please upload a CSV file.',
            'file.mimes' => 'The file must be a CSV file.',
            'file.max' => 'The file size must not exceed 5MB.',
            'table.required' => 'Table name is required.',
            'table.in' => 'The selected table is not supported for import.',
        ];
    }

    /**
     * Store the table from the route parameter into the request data for validation.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'table' => $this->route('table'),
        ]);
    }
}
