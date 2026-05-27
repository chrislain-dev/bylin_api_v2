<?php

declare(strict_types=1);

namespace Modules\Promotion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('promotions.create') === true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:promotions,code',
            'description' => 'nullable|string|max:300',
            'type' => ['required', Rule::in(['percentage', 'fixed_amount', 'buy_x_get_y'])],
            'value' => 'required|integer|min:0',

            'min_purchase_amount' => 'nullable|integer|min:0',
            'max_discount_amount' => 'nullable|integer|min:0',

            'usage_limit' => 'nullable|integer|min:5',
            'usage_limit_per_customer' => 'nullable|integer|min:1',

            'starts_at' => 'nullable|date|after_or_equal:now',
            'expires_at' => 'nullable|date|after:starts_at',

            'is_active' => 'boolean',
            'applicable_products' => 'nullable|array',
            'applicable_products.*' => 'exists:products,id',
            'applicable_categories' => 'nullable|array',
            'applicable_categories.*' => 'exists:categories,id',
            'metadata' => 'nullable|array',

            // Champs spécifiques pour buy_x_get_y
            'metadata.buy_quantity' => 'required_if:type,buy_x_get_y|integer|min:1',
            'metadata.get_quantity' => 'required_if:type,buy_x_get_y|integer|min:1',
            'metadata.discount_on_y' => 'nullable|integer|min:0|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de la promotion est obligatoire.',
            'code.unique' => 'Ce code de promotion est déjà utilisé.',
            'description.max' => 'La description ne peut pas dépasser 300 caractères.',
            'value.required' => 'La valeur de la promotion est obligatoire.',
            'value.integer' => 'La valeur doit être un nombre entier.',
            'value.min' => 'La valeur doit être positive.',

            'min_purchase_amount.integer' => 'Le montant minimum doit être un nombre entier (en FCFA).',
            'max_discount_amount.integer' => 'Le montant maximum doit être un nombre entier (en FCFA).',

            'usage_limit.min' => 'La limite d\'utilisation doit être au moins 5 (ou laissez vide pour illimité).',
            'usage_limit_per_customer.min' => 'La limite par client doit être au moins 1.',

            'starts_at.after_or_equal' => 'La date de début doit être aujourd\'hui ou dans le futur.',
            'expires_at.after' => 'La date de fin doit être après la date de début.',

            'type.in' => 'Le type de promotion doit être : percentage, fixed_amount ou buy_x_get_y.',

            'metadata.buy_quantity.required_if' => 'La quantité à acheter est obligatoire pour ce type de promotion.',
            'metadata.get_quantity.required_if' => 'La quantité offerte est obligatoire pour ce type de promotion.',
            'metadata.discount_on_y.max' => 'La réduction sur Y ne peut pas dépasser 100%.',
        ];
    }

    protected function prepareForValidation()
    {
        $merge = [];

        if ($this->has('code') && $this->code !== null && $this->code !== '') {
            $merge['code'] = strtoupper(trim($this->code));
        }

        // Convertir les chaînes vides en null pour les champs numériques
        if ($this->has('min_purchase_amount')) {
            $merge['min_purchase_amount'] = $this->min_purchase_amount === '' ? null : $this->min_purchase_amount;
        }

        if ($this->has('max_discount_amount')) {
            $merge['max_discount_amount'] = $this->max_discount_amount === '' ? null : $this->max_discount_amount;
        }

        if ($this->has('usage_limit')) {
            $merge['usage_limit'] = $this->usage_limit === '' ? null : $this->usage_limit;
        }

        if ($this->has('usage_limit_per_customer')) {
            $merge['usage_limit_per_customer'] = $this->usage_limit_per_customer === '' ? null : $this->usage_limit_per_customer;
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Validation conditionnelle selon le type
            if ($this->input('type') === 'percentage') {
                if ($this->input('value') > 100) {
                    $validator->errors()->add('value', 'Le pourcentage ne peut pas dépasser 100%.');
                }
            }

            if ($this->input('type') === 'buy_x_get_y') {
                if ($this->input('value') > 100) {
                    $validator->errors()->add('value', 'La réduction ne peut pas dépasser 100%.');
                }
            }

            // Valider les bonds de 500 FCFA seulement si la valeur est fournie
            if ($this->filled('min_purchase_amount')) {
                $amount = $this->input('min_purchase_amount');
                if ($amount > 0 && $amount % 500 !== 0) {
                    $validator->errors()->add('min_purchase_amount', 'Le montant minimum doit être un multiple de 500 FCFA.');
                }
            }

            if ($this->filled('max_discount_amount')) {
                $amount = $this->input('max_discount_amount');
                if ($amount > 0 && $amount % 500 !== 0) {
                    $validator->errors()->add('max_discount_amount', 'Le montant maximum doit être un multiple de 500 FCFA.');
                }
            }

            if ($this->filled('usage_limit')) {
                $limit = $this->input('usage_limit');
                if ($limit > 0 && $limit % 5 !== 0) {
                    $validator->errors()->add('usage_limit', 'La limite d\'utilisation doit être un multiple de 5.');
                }
            }
        });
    }
}
