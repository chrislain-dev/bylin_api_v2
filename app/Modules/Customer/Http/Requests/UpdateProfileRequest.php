<?php

declare(strict_types=1);

namespace Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $customerId = $this->user()->id;

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('customers', 'email')->ignore($customerId),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('customers', 'phone')->ignore($customerId),
            ],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female,other,prefer_not_to_say'],
            'avatar' => ['nullable', 'string', 'max:500'],
            'preferences' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already in use.',
            'phone.unique' => 'This phone number is already in use.',
            'date_of_birth.before' => 'Date of birth must be in the past.',
        ];
    }
}
