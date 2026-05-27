<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\StockOperation;
use Modules\Inventory\Enums\StockReason;

class BulkAdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage') === true;
    }

    public function rules(): array
    {
        return [
            'adjustments' => ['required', 'array', 'min:1', 'max:100'],
            'adjustments.*.product_id' => ['required_without:adjustments.*.variation_id', 'uuid', 'exists:products,id'],
            'adjustments.*.variation_id' => ['required_without:adjustments.*.product_id', 'uuid', 'exists:product_variations,id'],
            'adjustments.*.quantity' => ['required', 'integer', 'min:0'],
            'adjustments.*.operation' => ['required', Rule::in(StockOperation::values())],
            'adjustments.*.reason' => ['required', 'string', Rule::in(StockReason::values())],
            'adjustments.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'adjustments.required' => 'La liste des ajustements est requise.',
            'adjustments.array' => 'Les ajustements doivent être envoyés sous forme de tableau.',
            'adjustments.min' => 'Au moins un ajustement est requis.',
            'adjustments.max' => 'Vous ne pouvez pas traiter plus de 100 ajustements à la fois.',
            'adjustments.*.product_id.required_without' => 'Le produit est requis si aucune variation n’est fournie.',
            'adjustments.*.variation_id.required_without' => 'La variation est requise si aucun produit n’est fourni.',
            'adjustments.*.quantity.required' => 'La quantité est requise.',
            'adjustments.*.quantity.integer' => 'La quantité doit être un nombre entier.',
            'adjustments.*.quantity.min' => 'La quantité ne peut pas être négative.',
            'adjustments.*.operation.required' => 'L’opération est requise.',
            'adjustments.*.operation.in' => 'L’opération sélectionnée est invalide.',
            'adjustments.*.reason.required' => 'La raison est requise.',
            'adjustments.*.reason.in' => 'La raison sélectionnée est invalide.',
            'adjustments.*.notes.max' => 'Les notes ne peuvent pas dépasser 500 caractères.',
        ];
    }
}
