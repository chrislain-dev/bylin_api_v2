<?php

declare(strict_types=1);

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'manager', 'super_admin'])],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'confirmed', Password::min(10)->mixedCase()->numbers()],
            'password_confirmation' => ['nullable', 'string'],
            'send_invitation' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom est requis.',
            'email.required' => 'L\'email est requis.',
            'email.email' => 'L\'email doit être valide.',
            'email.unique' => 'Cet email est déjà utilisé.',
            'role.required' => 'Le rôle est requis.',
            'role.in' => 'Le rôle sélectionné est invalide.',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
            'password.min' => 'Le mot de passe doit contenir au moins :min caractères.',
        ];
    }
}
