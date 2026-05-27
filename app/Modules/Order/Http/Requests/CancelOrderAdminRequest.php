<?php

declare(strict_types=1);

namespace Modules\Order\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelOrderAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('orders.cancel') === true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => "Le motif d'annulation est obligatoire.",
            'reason.max' => 'Le motif ne peut pas dépasser 1000 caractères.',
        ];
    }

    public function attributes(): array
    {
        return [
            'reason' => "motif d'annulation",
        ];
    }
}
