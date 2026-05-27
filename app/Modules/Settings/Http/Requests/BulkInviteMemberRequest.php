<?php

declare(strict_types=1);

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkInviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') === true;
    }

    public function rules(): array
    {
        return [
            'invitations' => ['required', 'array', 'min:1', 'max:50'],
            'invitations.*.email' => ['required', 'email:rfc,dns', 'max:255', 'distinct:ignore_case'],
            'invitations.*.name' => ['nullable', 'string', 'max:255'],
            'invitations.*.role' => ['required', Rule::in(['admin', 'manager'])],
            'invitations.*.message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'invitations.required' => 'Au moins une invitation est requise.',
            'invitations.max' => 'Vous ne pouvez pas envoyer plus de 50 invitations à la fois.',
            'invitations.*.email.required' => 'L\'email est requis.',
            'invitations.*.email.email' => 'L\'email doit être valide.',
            'invitations.*.email.distinct' => 'Chaque email doit être unique dans la liste.',
            'invitations.*.role.required' => 'Le rôle est requis.',
            'invitations.*.role.in' => 'Le rôle sélectionné est invalide.',
        ];
    }
}
