<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Requête de création d'une valeur d'attribut
 *
 * Valide les données pour l'ajout d'une nouvelle valeur à un attribut
 * (ex: "Rouge" pour l'attribut Couleur, "XL" pour l'attribut Taille)
 */
class StoreAttributeValueRequest extends FormRequest
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
            // Attribut parent
            'attribute_id' => [
                'required',
                'string',
                'exists:attributes,id'
            ],

            // Valeur
            'value' => [
                'required',
                'string',
                'max:255'
            ],

            // Label optionnel (affichage)
            'label' => 'nullable|string|max:255',

            // Code couleur (uniquement pour type "color")
            'color_code' => [
                'nullable',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
                'required_if:type,color' // Si l'attribut est de type color
            ],

            // Ordre de tri
            'sort_order' => 'nullable|integer|min:0'
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
            'attribute_id.required' => 'L\'identifiant de l\'attribut est obligatoire',
            'attribute_id.exists' => 'L\'attribut spécifié n\'existe pas',
            'value.required' => 'La valeur est obligatoire',
            'value.max' => 'La valeur ne peut pas dépasser 255 caractères',
            'color_code.regex' => 'Le code couleur doit être au format hexadécimal (ex: #FF5733)',
            'color_code.required_if' => 'Le code couleur est obligatoire pour les attributs de type couleur'
        ];
    }

    /**
     * Prépare les données pour la validation
     */
    protected function prepareForValidation(): void
    {
        // Si le label n'est pas fourni, utiliser la valeur comme label
        if (!$this->has('label') && $this->has('value')) {
            $this->merge([
                'label' => $this->value
            ]);
        }

        // Valeur par défaut pour sort_order
        if (!$this->has('sort_order')) {
            $this->merge([
                'sort_order' => 0
            ]);
        }

        // Normaliser le code couleur (majuscules)
        if ($this->has('color_code')) {
            $this->merge([
                'color_code' => strtoupper($this->color_code)
            ]);
        }
    }
}
