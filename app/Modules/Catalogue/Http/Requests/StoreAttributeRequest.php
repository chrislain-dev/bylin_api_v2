<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Requête de création d'un attribut
 *
 * Valide les données pour la création d'un nouvel attribut produit
 * (ex: Couleur, Taille, Matière, etc.)
 */
class StoreAttributeRequest extends FormRequest
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
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Informations de base
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:attributes,name'
            ],
            'code' => [
                'required',
                'string',
                'max:100',
                'unique:attributes,code',
                'regex:/^[a-z0-9_-]+$/' // Format slug
            ],
            'type' => [
                'required',
                'string',
                Rule::in(['text', 'select', 'color', 'size', 'boolean'])
            ],

            // Configuration
            'is_filterable' => 'boolean',
            'sort_order' => 'integer|min:0',

            // Valeurs d'attributs (optionnel à la création)
            'values' => 'array',
            'values.*.value' => 'required_with:values|string|max:255',
            'values.*.label' => 'nullable|string|max:255',
            'values.*.color_code' => [
                'nullable',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/' // Format hexadécimal
            ],
            'values.*.sort_order' => 'nullable|integer|min:0'
        ];
    }

    /**
     * Messages de validation personnalisés
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de l\'attribut est obligatoire',
            'name.unique' => 'Un attribut avec ce nom existe déjà',
            'code.required' => 'Le code de l\'attribut est obligatoire',
            'code.unique' => 'Un attribut avec ce code existe déjà',
            'code.regex' => 'Le code doit contenir uniquement des lettres minuscules, chiffres, tirets et underscores',
            'type.required' => 'Le type d\'attribut est obligatoire',
            'type.in' => 'Le type d\'attribut doit être: text, select, color, size ou boolean',
            'values.*.color_code.regex' => 'Le code couleur doit être au format hexadécimal (ex: #FF5733)',
        ];
    }

    /**
     * Prépare les données pour la validation
     */
    protected function prepareForValidation(): void
    {
        // Générer automatiquement le code si non fourni
        if (!$this->has('code') && $this->has('name')) {
            $this->merge([
                'code' => \Illuminate\Support\Str::slug($this->name, '_')
            ]);
        }

        // Valeurs par défaut
        $this->merge([
            'is_filterable' => $this->boolean('is_filterable', false),
            'sort_order' => $this->integer('sort_order', 0)
        ]);
    }
}
