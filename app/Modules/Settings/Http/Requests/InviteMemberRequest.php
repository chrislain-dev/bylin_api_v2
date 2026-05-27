<?php

declare(strict_types=1);

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') === true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'manager'])],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'L\'email est requis.',
            'email.email' => 'L\'email doit être valide.',
            'role.required' => 'Le rôle est requis.',
            'role.in' => 'Le rôle sélectionné est invalide.',
            'message.max' => 'Le message ne peut pas dépasser 1000 caractères.',
        ];
    }
}
