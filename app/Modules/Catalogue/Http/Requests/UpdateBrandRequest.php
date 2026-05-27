<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.update') === true;
    }

    public function rules(): array
    {
        $brandId = $this->route('brand');

        if ($brandId instanceof \Illuminate\Database\Eloquent\Model) $brandId = $brandId->id;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:100',
                Rule::unique('brands', 'name')
                    ->ignore($brandId)
                    ->whereNull('deleted_at')
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000'
            ],

            'logo' => [
                'nullable',
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp,svg',
                'max:2048'
            ],

            'website' => [
                'nullable',
                'string',
                'url',
                'max:255'
            ],

            'is_active' => [
                'sometimes',
                'boolean'
            ],

            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
                'max:9999'
            ],

            'meta_data' => [
                'nullable',
                'array'
            ],

            'is_bylin_brand' => [
                'sometimes',
                'boolean'
            ],

            'remove_logo' => [
                'sometimes',
                'boolean'
            ]
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nom de la marque',
            'description' => 'description',
            'logo' => 'logo',
            'website' => 'site web',
            'is_active' => 'statut actif',
            'sort_order' => 'ordre de tri',
            'is_bylin_brand' => 'marque Bylin',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Une marque avec ce nom existe déjà.',
            'logo.image' => 'Le fichier doit être une image.',
            'logo.mimes' => 'Le logo doit être au format JPEG, PNG ou WebP.',
            'logo.max' => 'Le logo ne doit pas dépasser 2 Mo.',
            'website.url' => 'Le site web doit être une URL valide.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN)
            ]);
        }

        if ($this->has('is_bylin_brand')) {
            $this->merge([
                'is_bylin_brand' => filter_var($this->is_bylin_brand, FILTER_VALIDATE_BOOLEAN)
            ]);
        }

        if ($this->has('remove_logo')) {
            $this->merge([
                'remove_logo' => filter_var($this->remove_logo, FILTER_VALIDATE_BOOLEAN)
            ]);
        }

        if ($this->has('sort_order')) {
            $this->merge([
                'sort_order' => (int) $this->sort_order
            ]);
        }
    }
}
