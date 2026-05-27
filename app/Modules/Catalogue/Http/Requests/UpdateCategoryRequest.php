<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Requête de mise à jour de catégorie
 *
 * Valide les données lors de la modification d'une catégorie existante.
 */
class UpdateCategoryRequest extends FormRequest
{
    /**
     * Détermine si l'utilisateur est autorisé à faire cette requête
     */
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.update') === true;
    }

    /**
     * Règles de validation
     */
    public function rules(): array
    {
        $categoryId = $this->route('category') ?? $this->route('id');

        return [
            // Hiérarchie
            'parent_id' => [
                'nullable',
                'uuid',
                'exists:categories,id',
                function ($attribute, $value, $fail) use ($categoryId) {
                    // Ne peut pas se définir comme son propre parent
                    if ($value === $categoryId) {
                        $fail('Une catégorie ne peut pas être son propre parent.');
                    }

                    // Ne peut pas définir un enfant comme parent (éviter les boucles)
                    if ($value) {
                        $category = \Modules\Catalogue\Models\Category::find($categoryId);
                        $newParent = \Modules\Catalogue\Models\Category::find($value);

                        if ($category && $newParent && $category->isAncestorOf($newParent)) {
                            $fail('Impossible de définir une sous-catégorie comme parent.');
                        }

                        // Vérifier la profondeur maximale
                        if ($newParent && $newParent->level >= 3) {
                            $fail('La profondeur maximale de catégories est atteinte.');
                        }
                    }
                },
            ],

            // Informations de base
            'name' => [
                'sometimes',
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
                'max:2048',
            ],
            'remove_image' => [
                'sometimes',
                'boolean',
            ],
            'icon' => [
                'nullable',
                'string',
                'max:50',
            ],
            'color' => [
                'nullable',
                'string',
                'regex:/^#[0-9A-F]{6}$/i',
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
}
