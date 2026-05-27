<?php

declare(strict_types=1);

namespace Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerAdminRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('customers.create') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:customers,email',
            'phone' => 'nullable|string|max:20|unique:customers,phone',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other,prefer_not_to_say',
            'status' => 'nullable|in:active,inactive,suspended',
            'send_credentials' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'Le prénom est requis.',
            'last_name.required' => 'Le nom de famille est requis.',
            'email.required' => 'L\'adresse email est requise.',
            'email.email' => 'L\'adresse email doit être valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
            'date_of_birth.before' => 'La date de naissance doit être antérieure à aujourd\'hui.',
            'status.in' => 'Le statut sélectionné est invalide.',
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
            'status' => 'statut',
            'send_credentials' => 'envoyer les identifiants',
        ];
    }
}
