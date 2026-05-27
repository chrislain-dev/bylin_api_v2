<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Requête de mise à jour d'une valeur d'attribut
 *
 * Valide les données pour la modification d'une valeur d'attribut existante
 */
class UpdateAttributeValueRequest extends FormRequest
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
        return [
            // Valeur
            'value' => [
                'sometimes',
                'required',
                'string',
                'max:255'
            ],

            // Label optionnel
            'label' => 'sometimes|nullable|string|max:255',

            // Code couleur
            'color_code' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/'
            ],

            // Ordre de tri
            'sort_order' => 'sometimes|nullable|integer|min:0'
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
            'value.max' => 'La valeur ne peut pas dépasser 255 caractères',
            'color_code.regex' => 'Le code couleur doit être au format hexadécimal (ex: #FF5733)'
        ];
    }

    /**
     * Prépare les données pour la validation
     */
    protected function prepareForValidation(): void
    {
        // Normaliser le code couleur (majuscules)
        if ($this->has('color_code') && $this->color_code) {
            $this->merge([
                'color_code' => strtoupper($this->color_code)
            ]);
        }
    }
}
