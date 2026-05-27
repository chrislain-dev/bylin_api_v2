<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Requête de mise à jour d'un attribut
 *
 * Valide les données pour la mise à jour d'un attribut existant
 */
class UpdateAttributeRequest extends FormRequest
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
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $attributeId = $this->route('attribute') ?? $this->route('id');

        return [
            // Informations de base
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('attributes', 'name')->ignore($attributeId)
            ],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('attributes', 'code')->ignore($attributeId),
                'regex:/^[a-z0-9_-]+$/'
            ],
            'type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['text', 'select', 'color', 'size', 'boolean'])
            ],

            // Configuration
            'is_filterable' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',

            // Valeurs d'attributs
            'values' => 'sometimes|array',
            'values.*.id' => 'nullable|string|exists:attribute_values,id',
            'values.*.value' => 'required_with:values|string|max:255',
            'values.*.label' => 'nullable|string|max:255',
            'values.*.color_code' => [
                'nullable',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/'
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
            'name.unique' => 'Un attribut avec ce nom existe déjà',
            'code.unique' => 'Un attribut avec ce code existe déjà',
            'code.regex' => 'Le code doit contenir uniquement des lettres minuscules, chiffres, tirets et underscores',
            'type.in' => 'Le type d\'attribut doit être: text, select, color, size ou boolean',
            'values.*.id.exists' => 'Une des valeurs d\'attribut n\'existe pas',
            'values.*.color_code.regex' => 'Le code couleur doit être au format hexadécimal (ex: #FF5733)',
        ];
    }

    /**
     * Prépare les données pour la validation
     */
    protected function prepareForValidation(): void
    {
        // Convertir is_filterable en boolean si présent
        if ($this->has('is_filterable')) {
            $this->merge([
                'is_filterable' => $this->boolean('is_filterable')
            ]);
        }
    }
}
