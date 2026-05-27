<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class BulkBrandIdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $routeName = (string) $this->route()?->getName();

        if (Str::contains($routeName, ['destroy', 'force-delete'])) {
            return $this->user()?->can('catalogue.delete') === true;
        }

        return $this->user()?->can('catalogue.update') === true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'uuid', 'distinct', 'exists:brands,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'La liste des identifiants est obligatoire.',
            'ids.array' => 'Les identifiants doivent être envoyés sous forme de tableau.',
            'ids.min' => 'Vous devez sélectionner au moins un élément.',
            'ids.max' => 'Vous ne pouvez pas traiter plus de 500 éléments à la fois.',
            'ids.*.uuid' => 'Chaque identifiant doit être un UUID valide.',
            'ids.*.distinct' => 'La liste contient des identifiants en doublon.',
            'ids.*.exists' => 'Un ou plusieurs éléments sélectionnés sont introuvables.',
        ];
    }
}
