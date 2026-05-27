<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Catalogue\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.create') === true;
    }

    public function rules(): array
    {
        $rules = [
            // Informations de base
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:500'],

            // Prix
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'compare_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'gt:price'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            // Stock
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'track_inventory' => ['boolean'],

            // Précommande
            'is_preorder_enabled' => ['boolean'],
            'preorder_available_date' => ['nullable', 'date', 'after:today'],
            'preorder_limit' => ['nullable', 'integer', 'min:1'],
            'preorder_message' => ['nullable', 'string', 'max:255'],
            'preorder_terms' => ['nullable', 'string', 'max:1000'],

            // SEO
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'meta_keywords' => ['nullable', 'array'],

            // Dimensions
            'weight' => ['nullable', 'numeric', 'min:0'],
            'dimensions' => ['nullable', 'array'],
            'dimensions.length' => ['nullable', 'numeric', 'min:0'],
            'dimensions.width' => ['nullable', 'numeric', 'min:0'],
            'dimensions.height' => ['nullable', 'numeric', 'min:0'],

            // Relations
            'brand_id' => ['nullable', 'exists:brands,id'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['exists:categories,id'],

            // Status
            'status' => ['required', Rule::in(['draft', 'active', 'inactive', 'archived'])],
            'is_featured' => ['boolean'],
            'is_new' => ['boolean'],
            'is_on_sale' => ['boolean'],

            // Variabilité
            'is_variable' => ['boolean'],
            
            'variations' => ['nullable', 'array'],
            'variations.*.sku' => ['nullable', 'string', 'max:100'],
            'variations.*.variation_name' => ['required_with:variations.*', 'string', 'max:255'],

            // Prix des variations
            'variations.*.price' => ['required_with:variations.*', 'numeric', 'min:0'],
            'variations.*.compare_price' => ['nullable', 'numeric', 'min:0'],
            'variations.*.cost_price' => ['nullable', 'numeric', 'min:0'],

            // Stock des variations
            'variations.*.stock_quantity' => ['required_with:variations.*', 'integer', 'min:0'],
            'variations.*.stock_status' => ['nullable', 'string', Rule::in(['in_stock', 'out_of_stock', 'on_backorder'])],

            // Autres champs des variations
            'variations.*.barcode' => ['nullable', 'string', 'max:100'],
            'variations.*.is_active' => ['boolean'],
            'variations.*.attributes' => ['nullable', 'array'],

            // Bylin Authenticity
            'requires_authenticity' => ['boolean'],
            'authenticity_codes_count' => ['nullable', 'integer', 'min:1', 'max:10000'],

            // Media
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ];

        if ($this->isBylinProduct()) {
            $rules['collection_id'] = ['required', 'uuid', 'exists:collections,id'];
        } else {
            $rules['collection_id'] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom du produit est obligatoire.',
            'price.required' => 'Le prix est obligatoire.',
            'price.min' => 'Le prix doit être positif.',
            'compare_price.gt' => 'Le prix comparatif doit être supérieur au prix de vente.',
            'preorder_available_date.after' => 'La date de disponibilité doit être dans le futur.',

            'collection_id.required' => 'Les produits de la marque Bylin doivent appartenir à une collection.',
            'collection_id.exists' => 'Cette collection n\'existe pas.',
            'collection_id.prohibited' => 'Seuls les produits Bylin peuvent avoir une collection.',

            'variations.*.variation_name.required_with' => 'Le nom de la variation est obligatoire.',
            'variations.*.price.required_with' => 'Le prix de la variation est obligatoire.',
            'variations.*.stock_quantity.required_with' => 'La quantité en stock est obligatoire.',
            'variations.*.stock_status.in' => 'Le statut du stock est invalide.',

            'images.*.image' => 'Le fichier doit être une image.',
            'images.*.max' => 'L\'image ne doit pas dépasser 5Mo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Convertir les booléens du produit
        $this->merge([
            'track_inventory' => $this->boolean('track_inventory', true),
            'is_preorder_enabled' => $this->boolean('is_preorder_enabled', false),
            'is_featured' => $this->boolean('is_featured', false),
            'is_new' => $this->boolean('is_new', false),
            'is_on_sale' => $this->boolean('is_on_sale', false),
            'is_variable' => $this->boolean('is_variable', false),
            'requires_authenticity' => $this->boolean('requires_authenticity', false),
        ]);

        if ($this->has('variations') && is_array($this->variations)) {
            $normalized = [];

            foreach ($this->variations as $variation) {

                if (isset($variation['is_active'])) {
                    $variation['is_active'] = filter_var($variation['is_active'], FILTER_VALIDATE_BOOLEAN);
                }

                if (!isset($variation['stock_status']) && isset($variation['stock_quantity'])) {
                    $variation['stock_status'] = $variation['stock_quantity'] > 0 ? 'in_stock' : 'out_of_stock';
                }

                // S'assurer que attributes est un tableau
                if (!isset($variation['attributes'])) {
                    $variation['attributes'] = [];
                }

                $normalized[] = $variation;
            }

            $this->merge(['variations' => $normalized]);
        }
    }

    protected function isBylinProduct(): bool
    {
        if (!$this->has('brand_id')) {
            return false;
        }

        $brand = Brand::find($this->input('brand_id'));

        return $brand && $brand->is_bylin_brand;
    }

    protected function passedValidation(): void
    {
        if ($this->isBylinProduct()) {
            $this->merge(['requires_authenticity' => true]);
        }
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        return array_merge([
            'stock_quantity' => 0,
            'low_stock_threshold' => 5,
            'track_inventory' => true,
            'is_preorder_enabled' => false,
            'is_featured' => false,
            'is_new' => false,
            'is_on_sale' => false,
            'is_variable' => false,
            'requires_authenticity' => false,
            'status' => 'draft',
        ], $data);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {

            if ($this->has('collection_id') && !$this->isBylinProduct()) {
                $validator->errors()->add(
                    'collection_id',
                    'Seuls les produits de la marque Bylin peuvent appartenir à une collection.'
                );
            }

            if ($this->has('compare_price') && $this->has('price')) {
                if ($this->input('compare_price') <= $this->input('price')) {
                    $validator->errors()->add(
                        'compare_price',
                        'Le prix comparatif doit être supérieur au prix de vente pour indiquer une réduction.'
                    );
                }
            }

            if ($this->is_preorder_enabled && $this->stock_quantity > 0) {
                $validator->errors()->add(
                    'is_preorder_enabled',
                    'Un produit avec du stock ne peut pas être en précommande manuelle.'
                );
            }

            if ($this->requires_authenticity && $this->brand_id) {
                $brand = \Modules\Catalogue\Models\Brand::find($this->brand_id);
                if ($brand && $brand->slug !== 'bylin') {
                    $validator->errors()->add(
                        'requires_authenticity',
                        'L\'authentification est réservée aux produits Bylin.'
                    );
                }
            }
            
            if ($this->is_variable && $this->has('variations')) {
                if (empty($this->variations)) {
                    $validator->errors()->add(
                        'variations',
                        'Un produit variable doit avoir au moins une variation.'
                    );
                }
            }
        });
    }
}
