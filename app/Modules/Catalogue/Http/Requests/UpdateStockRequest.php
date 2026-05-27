<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage') === true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:0'],
            'operation' => ['required', Rule::in(['set', 'add', 'sub'])],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'La quantité est requise',
            'quantity.integer' => 'La quantité doit être un nombre entier',
            'quantity.min' => 'La quantité ne peut pas être négative',
            'operation.required' => 'L\'opération est requise',
            'operation.in' => 'L\'opération doit être: set, add ou sub',
        ];
    }
}
