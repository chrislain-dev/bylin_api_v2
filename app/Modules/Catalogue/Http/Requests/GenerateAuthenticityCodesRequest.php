<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateAuthenticityCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('authenticity.manage') === true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'serial_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Le produit est obligatoire.',
            'product_id.exists' => 'Le produit sélectionné est introuvable.',
            'quantity.required' => 'La quantité est obligatoire.',
            'quantity.min' => 'Vous devez générer au moins un code.',
            'quantity.max' => 'Vous ne pouvez pas générer plus de 1000 codes à la fois.',
            'serial_prefix.regex' => 'Le préfixe ne doit contenir que des lettres, chiffres, tirets ou underscores.',
        ];
    }
}
