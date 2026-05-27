<?php

declare(strict_types=1);

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') === true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Le statut est requis.',
            'status.in' => 'Le statut sélectionné est invalide.',
            'reason.max' => 'La raison ne peut pas dépasser 500 caractères.',
        ];
    }
}
