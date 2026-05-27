<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Catalogue\Models\Product;
use Modules\Inventory\Enums\StockOperation;
use Modules\Inventory\Enums\StockReason;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage') === true;
    }

    public function rules(): array
    {
        return [
            // Champs communs
            'product_id' => [
                'required',
                'uuid',
                'exists:products,id,deleted_at,NULL'
            ],
            'reason' => [
                'required',
                'string',
                Rule::in(StockReason::values())
            ],
            'notes' => [
                'nullable',
                'string',
                'max:500'
            ],

            // Produit Simple
            'quantity' => [
                'nullable',
                'integer',
                'min:0'
            ],
            'operation' => [
                'nullable',
                'string',
                Rule::in(StockOperation::values())
            ],

            // Produit Variable
            'variations' => [
                'nullable',
                'array',
                'min:1'
            ],
            'variations.*.id' => [
                'required_with:variations',
                'uuid',
                'exists:product_variations,id,deleted_at,NULL'
            ],
            'variations.*.quantity' => [
                'required_with:variations',
                'integer',
                'min:0'
            ],
            'variations.*.operation' => [
                'required_with:variations',
                'string',
                Rule::in(StockOperation::values())
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            if (!isset($data['product_id'])) {
                return;
            }

            $product = Product::withCount('variations')->find($data['product_id']);

            if (!$product) {
                return;
            }
            // PRODUIT VARIABLE
            if ($product->variations_count > 0) {
                if (empty($data['variations'])) {
                    $validator->errors()->add(
                        'variations',
                        'Ce produit possède des variations. Vous devez ajuster le stock via le tableau "variations".'
                    );
                }

                if (isset($data['quantity']) || isset($data['operation'])) {
                    $validator->errors()->add(
                        'quantity',
                        'Impossible d\'ajuster le stock global d\'un produit variable. Utilisez "variations".'
                    );
                }

                // Vérifier l'appartenance des variations
                if (!empty($data['variations'])) {
                    $variationIds = collect($data['variations'])->pluck('id')->all();
                    $validCount = $product->variations()
                        ->whereIn('id', $variationIds)
                        ->count();

                    if ($validCount !== count($variationIds)) {
                        $validator->errors()->add(
                            'variations',
                            'Une ou plusieurs variations ne correspondent pas à ce produit.'
                        );
                    }
                }
            }
            // PRODUIT SIMPLE
            else {
                if (!isset($data['quantity'])) {
                    $validator->errors()->add(
                        'quantity',
                        'Le champ "quantity" est requis pour un produit simple.'
                    );
                }

                if (!isset($data['operation'])) {
                    $validator->errors()->add(
                        'operation',
                        'Le champ "operation" est requis pour un produit simple.'
                    );
                }

                if (!empty($data['variations'])) {
                    $validator->errors()->add(
                        'variations',
                        'Ce produit n\'a pas de variations. Utilisez "quantity" et "operation" directement.'
                    );
                }

                // Validation métier
                if (isset($data['quantity'], $data['operation'])) {
                    if (in_array($data['operation'], ['add', 'sub']) && $data['quantity'] === 0) {
                        $validator->errors()->add(
                            'quantity',
                            'La quantité doit être supérieure à 0 pour une opération d\'ajout ou de retrait.'
                        );
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'L\'identifiant du produit est requis.',
            'product_id.uuid' => 'L\'identifiant du produit est invalide.',
            'product_id.exists' => 'Le produit spécifié n\'existe pas.',

            'reason.required' => 'La raison de l\'ajustement est requise.',
            'reason.in' => 'La raison sélectionnée est invalide.',

            'operation.in' => 'L\'opération sélectionnée est invalide.',

            'quantity.integer' => 'La quantité doit être un nombre entier.',
            'quantity.min' => 'La quantité ne peut pas être négative.',

            'notes.max' => 'Les notes ne peuvent pas dépasser 500 caractères.',

            'variations.required' => 'Les variations sont requises pour ce produit.',
            'variations.array' => 'Les variations doivent être un tableau.',
            'variations.min' => 'Au moins une variation est requise.',

            'variations.*.id.required_with' => 'L\'identifiant de la variation est requis.',
            'variations.*.id.uuid' => 'L\'identifiant de la variation est invalide.',
            'variations.*.id.exists' => 'Une variation spécifiée n\'existe pas.',

            'variations.*.quantity.required_with' => 'La quantité de la variation est requise.',
            'variations.*.quantity.integer' => 'La quantité de la variation doit être un nombre entier.',
            'variations.*.quantity.min' => 'La quantité de la variation ne peut pas être négative.',

            'variations.*.operation.required_with' => 'L\'opération de la variation est requise.',
            'variations.*.operation.in' => 'L\'opération de la variation est invalide.',
        ];
    }

    public function attributes(): array
    {
        return [
            'product_id' => 'produit',
            'quantity' => 'quantité',
            'operation' => 'opération',
            'reason' => 'raison',
            'notes' => 'notes',
            'variations' => 'variations',
            'variations.*.id' => 'variation',
            'variations.*.quantity' => 'quantité de la variation',
            'variations.*.operation' => 'opération de la variation',
        ];
    }
}
