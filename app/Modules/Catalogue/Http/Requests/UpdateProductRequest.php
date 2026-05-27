<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.update') === true;
    }

    public function rules(): array
    {
        $productId = $this->route('product');

        return [
            // ===================================
            // INFORMATIONS DE BASE
            // ===================================
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:500'],

            // ===================================
            // PRIX
            // ===================================
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99'],
            'compare_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'gt:price'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            // ===================================
            // STOCK
            // ===================================
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'track_inventory' => ['boolean'],

            // ===================================
            // PRÉCOMMANDE
            // ===================================
            'is_preorder_enabled' => ['boolean'],
            'preorder_auto_enabled' => ['boolean'],
            'preorder_available_date' => ['nullable', 'date', 'after_or_equal:today'],
            'preorder_limit' => ['nullable', 'integer', 'min:1'],
            'preorder_message' => ['nullable', 'string', 'max:255'],
            'preorder_terms' => ['nullable', 'string', 'max:1000'],

            // ===================================
            // AUTHENTICITÉ BYLIN
            // ===================================
            'requires_authenticity' => ['boolean'],
            'authenticity_codes_count' => ['nullable', 'integer', 'min:0'],

            // ===================================
            // SEO
            // ===================================
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],

            // ===================================
            // DIMENSIONS
            // ===================================
            'weight' => ['nullable', 'numeric', 'min:0'],
            'dimensions' => ['nullable', 'array'],
            'dimensions.length' => ['nullable', 'numeric', 'min:0'],
            'dimensions.width' => ['nullable', 'numeric', 'min:0'],
            'dimensions.height' => ['nullable', 'numeric', 'min:0'],
            'dimensions.unit' => ['nullable', 'string', Rule::in(['cm', 'in'])],

            // ===================================
            // RELATIONS
            // ===================================
            'categories' => ['sometimes', 'array', 'min:1'],
            'categories.*' => ['required', 'uuid', 'exists:categories,id'],
            'brand_id' => ['nullable', 'uuid', 'exists:brands,id'],

            // ===================================
            // STATUS
            // ===================================
            'status' => [
                'sometimes',
                'required',
                Rule::in(['draft', 'active', 'inactive', 'out_of_stock', 'preorder', 'discontinued'])
            ],
            'is_featured' => ['boolean'],
            'is_new' => ['boolean'],
            'is_on_sale' => ['boolean'],

            // ===================================
            // VARIABILITÉ
            // ===================================
            'is_variable' => ['boolean'],

            // ===================================
            // VARIATIONS - ✅ COMPLET ET ALIGNÉ AVEC LA MIGRATION
            // ===================================
            'variations' => ['nullable', 'array'],
            'variations.*.id' => ['nullable', 'uuid', 'exists:product_variations,id'],
            'variations.*.sku' => ['nullable', 'string', 'max:100'],
            'variations.*.variation_name' => ['required_with:variations.*', 'string', 'max:255'],

            // Prix
            'variations.*.price' => ['required_with:variations.*', 'numeric', 'min:0'],
            'variations.*.compare_price' => ['nullable', 'numeric', 'min:0'],
            'variations.*.cost_price' => ['nullable', 'numeric', 'min:0'],

            // Stock
            'variations.*.stock_quantity' => ['required_with:variations.*', 'integer', 'min:0'],
            'variations.*.stock_status' => ['nullable', 'string', Rule::in(['in_stock', 'out_of_stock', 'on_backorder'])],

            // Autres
            'variations.*.barcode' => ['nullable', 'string', 'max:100'],
            'variations.*.is_active' => ['boolean'],
            'variations.*.attributes' => ['nullable', 'array'],
            'variations.*._destroy' => ['boolean'],

            // ===================================
            // MEDIA
            // ===================================
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            'images_to_delete' => ['nullable', 'array'],
            'images_to_delete.*' => ['integer', 'exists:media,id'],
        ];
    }

    public function messages(): array
    {
        return [
            // Produit
            'name.required' => 'Le nom du produit est obligatoire.',
            'price.required' => 'Le prix est obligatoire.',
            'price.min' => 'Le prix doit être positif.',
            'compare_price.gt' => 'Le prix de comparaison doit être supérieur au prix de vente.',
            'categories.required' => 'Au moins une catégorie est obligatoire.',
            'categories.*.exists' => 'Une ou plusieurs catégories n\'existent pas.',
            'brand_id.exists' => 'Cette marque n\'existe pas.',

            // Précommande
            'preorder_available_date.after_or_equal' => 'La date de disponibilité doit être aujourd\'hui ou dans le futur.',

            // Media
            'images.*.image' => 'Le fichier doit être une image.',
            'images.*.max' => 'L\'image ne doit pas dépasser 5Mo.',
            'images.*.mimes' => 'Format accepté : JPEG, PNG, JPG, WEBP.',

            // Variations
            'variations.*.variation_name.required_with' => 'Le nom de la variation est obligatoire.',
            'variations.*.price.required_with' => 'Le prix de la variation est obligatoire.',
            'variations.*.stock_quantity.required_with' => 'La quantité en stock est obligatoire.',
            'variations.*.stock_status.in' => 'Le statut du stock est invalide.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Convertir les booléens du produit
        $booleanFields = [
            'track_inventory',
            'is_preorder_enabled',
            'preorder_auto_enabled',
            'is_featured',
            'is_new',
            'is_on_sale',
            'is_variable',
            'requires_authenticity',
        ];

        foreach ($booleanFields as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $this->boolean($field)]);
            }
        }

        // Convertir dimensions si string JSON
        if ($this->has('dimensions') && is_string($this->dimensions)) {
            $this->merge(['dimensions' => json_decode($this->dimensions, true)]);
        }

        // ✅ Normaliser les variations
        if ($this->has('variations') && is_array($this->variations)) {
            $normalized = [];

            foreach ($this->variations as $variation) {
                // Convertir is_active
                if (isset($variation['is_active'])) {
                    $variation['is_active'] = filter_var($variation['is_active'], FILTER_VALIDATE_BOOLEAN);
                }

                // Convertir _destroy
                if (isset($variation['_destroy'])) {
                    $variation['_destroy'] = filter_var($variation['_destroy'], FILTER_VALIDATE_BOOLEAN);
                }

                // Déterminer stock_status automatiquement si non fourni
                if (!isset($variation['stock_status']) && isset($variation['stock_quantity'])) {
                    $variation['stock_status'] = $variation['stock_quantity'] > 0 ? 'in_stock' : 'out_of_stock';
                }

                $normalized[] = $variation;
            }

            $this->merge(['variations' => $normalized]);
        }
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        // Nettoyer les variations marquées pour suppression
        if (isset($validated['variations'])) {
            $validated['variations'] = array_filter(
                $validated['variations'],
                fn($v) => !($v['_destroy'] ?? false)
            );

            // Réindexer le tableau
            $validated['variations'] = array_values($validated['variations']);
        }

        return $validated;
    }
}
