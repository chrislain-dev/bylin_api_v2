<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update Collection Request
 *
 * Validation pour la mise à jour d'une collection Bylin
 */
class UpdateCollectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.update') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $collectionId = $this->route('collection') ?? $this->route('id');

        return [
            // Basic information
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],

            // Collection metadata
            'season' => ['nullable', 'string', 'max:100'],
            'theme' => ['nullable', 'string', 'max:100'],
            'release_date' => ['nullable', 'date'],
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
}
