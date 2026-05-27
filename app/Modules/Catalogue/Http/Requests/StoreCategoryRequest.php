<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Requête de création de catégorie
 *
 * Valide les données lors de la création d'une nouvelle catégorie.
 */
class StoreCategoryRequest extends FormRequest
{
    /**
     * Détermine si l'utilisateur est autorisé à faire cette requête
     */
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.create') === true;
    }

    /**
     * Règles de validation
     */
    public function rules(): array
    {
        return [
            // Hiérarchie
            'parent_id' => [
                'nullable',
                'uuid',
                'exists:categories,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $parent = \Modules\Catalogue\Models\Category::find($value);
                        // Vérifier la profondeur maximale (ex: 3 niveaux)
                        if ($parent && $parent->level >= 3) {
                            $fail('La profondeur maximale de catégories est atteinte.');
                        }
                    }
                },
            ],

            // Informations de base
            'name' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],
            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],

            // Média
            'image' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:2048', // 2MB
            ],
            'icon' => [
                'nullable',
                'string',
                'max:50',
            ],
            'color' => [
                'nullable',
                'string',
                'regex:/^#[0-9A-F]{6}$/i', // Format hex color
            ],

            // Configuration
            'is_active' => [
                'sometimes',
                'boolean',
            ],
            'is_visible_in_menu' => [
                'sometimes',
                'boolean',
            ],
            'is_featured' => [
                'sometimes',
                'boolean',
            ],
            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
                'max:9999',
            ],

            // SEO
            'meta_title' => [
                'nullable',
                'string',
                'max:255',
            ],
            'meta_description' => [
                'nullable',
                'string',
                'max:500',
            ],

            // Métadonnées
            'meta_data' => [
                'nullable',
                'array',
            ],
        ];
    }

    /**
     * Messages d'erreur personnalisés
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => 'La catégorie parente sélectionnée n\'existe pas.',
            'name.required' => 'Le nom de la catégorie est obligatoire.',
            'name.min' => 'Le nom doit contenir au moins 2 caractères.',
            'name.max' => 'Le nom ne peut pas dépasser 100 caractères.',
            'description.max' => 'La description ne peut pas dépasser 2000 caractères.',
            'image.image' => 'Le fichier doit être une image.',
            'image.mimes' => 'L\'image doit être au format JPEG, PNG ou WebP.',
            'image.max' => 'L\'image ne peut pas dépasser 2 Mo.',
            'color.regex' => 'La couleur doit être au format hexadécimal (ex: #FF5733).',
            'sort_order.min' => 'L\'ordre ne peut pas être négatif.',
            'sort_order.max' => 'L\'ordre ne peut pas dépasser 9999.',
        ];
    }

    /**
     * Prépare les données avant validation
     */
    protected function prepareForValidation(): void
    {
        // Valeurs par défaut
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
            'is_visible_in_menu' => $this->boolean('is_visible_in_menu', true),
            'is_featured' => $this->boolean('is_featured', false),
            'sort_order' => $this->integer('sort_order', 0),
        ]);
    }
}
