<?php

declare(strict_types=1);

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') === true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'manager', 'super_admin'])],
        ];
    }

    public function messages(): array
    {
        return [
            'role.required' => 'Le rôle est requis.',
            'role.in' => 'Le rôle sélectionné est invalide.',
        ];
    }
}
