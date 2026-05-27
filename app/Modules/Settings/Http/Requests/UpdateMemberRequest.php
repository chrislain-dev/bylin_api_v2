<?php

declare(strict_types=1);

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') === true;
    }

    public function rules(): array
    {
        $memberId = (string) $this->route('member');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email:rfc,dns', 'max:255', Rule::unique('users', 'email')->ignore($memberId)],
            'phone' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
        ];
    }

    public function messages(): array
    {
        return [
            'email.email' => 'L\'email doit être valide.',
            'email.unique' => 'Cet email est déjà utilisé.',
            'status.in' => 'Le statut sélectionné est invalide.',
        ];
    }
}
