<?php

declare(strict_types=1);

namespace Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerAdminRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('customers.update') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $customerId = $this->route('id');

        return [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('customers')->ignore($customerId),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('customers')->ignore($customerId),
            ],
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other,prefer_not_to_say',
            'preferences' => 'nullable|array',
            // Note: Status updates are handled via separate endpoints/requests
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'email.email' => 'L\'adresse email doit être valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
            'date_of_birth.before' => 'La date de naissance doit être antérieure à aujourd\'hui.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'prénom',
            'last_name' => 'nom',
            'email' => 'adresse email',
            'phone' => 'téléphone',
            'date_of_birth' => 'date de naissance',
            'gender' => 'genre',
            'preferences' => 'préférences',
        ];
    }
}
