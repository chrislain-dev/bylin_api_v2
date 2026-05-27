<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDestroyProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.delete') === true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'uuid', 'distinct', 'exists:products,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Veuillez sélectionner au moins un produit.',
            'ids.array' => 'La liste des produits est invalide.',
            'ids.max' => 'Vous ne pouvez pas traiter plus de 500 produits à la fois.',
            'ids.*.uuid' => 'Un identifiant de produit est invalide.',
            'ids.*.distinct' => 'La liste contient des produits en double.',
            'ids.*.exists' => 'Un ou plusieurs produits sélectionnés n’existent pas.',
        ];
    }
}
