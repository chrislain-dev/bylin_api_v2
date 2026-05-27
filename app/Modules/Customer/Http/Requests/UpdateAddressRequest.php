<?php

declare(strict_types=1);

namespace Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('country_code') && ! $this->filled('country')) {
            $this->merge(['country' => strtoupper((string) $this->input('country_code'))]);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:billing,shipping'],
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'string', 'max:30'],
            'address_line_1' => ['sometimes', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Address type must be billing or shipping.',
        ];
    }
}
