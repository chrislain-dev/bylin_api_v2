<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Store Collection Request
 *
 * Validation pour la création d'une collection Bylin
 */
class StoreCollectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.create') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // Basic information
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],

            // Collection metadata
            'season' => ['nullable', 'string', 'max:100'],
            'theme' => ['nullable', 'string', 'max:100'],
            'release_date' => ['nullable', 'date', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after:release_date'],

            // Images
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'banner_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],

            // Status
            'is_active' => ['boolean'],
            'is_featured' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],

            // SEO
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords' => ['nullable', 'array'],
            'meta_keywords.*' => ['string', 'max:100'],

            // Extra data
            'meta_data' => ['nullable', 'array'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de la collection est obligatoire.',
            'name.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'release_date.after_or_equal' => 'La date de sortie ne peut pas être dans le passé.',
            'end_date.after' => 'La date de fin doit être après la date de sortie.',
            'cover_image.image' => 'Le fichier doit être une image.',
            'cover_image.mimes' => 'L\'image de couverture doit être au format jpeg, png, jpg ou webp.',
            'cover_image.max' => 'L\'image de couverture ne peut pas dépasser 2 Mo.',
            'banner_image.image' => 'Le fichier doit être une image.',
            'banner_image.mimes' => 'La bannière doit être au format jpeg, png, jpg ou webp.',
            'banner_image.max' => 'La bannière ne peut pas dépasser 2 Mo.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom de la collection',
            'description' => 'description',
            'season' => 'saison',
            'theme' => 'thème',
            'release_date' => 'date de sortie',
            'end_date' => 'date de fin',
            'cover_image' => 'image de couverture',
            'banner_image' => 'bannière',
            'is_active' => 'statut actif',
            'is_featured' => 'mise en avant',
            'sort_order' => 'ordre de tri',
            'meta_title' => 'titre SEO',
            'meta_description' => 'description SEO',
            'meta_keywords' => 'mots-clés SEO',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default values
        if (!$this->has('is_active')) {
            $this->merge(['is_active' => true]);
        }

        if (!$this->has('is_featured')) {
            $this->merge(['is_featured' => false]);
        }

        if (!$this->has('sort_order')) {
            $this->merge(['sort_order' => 0]);
        }
    }
}
