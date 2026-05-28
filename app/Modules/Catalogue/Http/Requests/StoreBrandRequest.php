<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBrandRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.create') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:100|unique:brands,name',
            'is_bylin_brand' => 'boolean',
            'description' => 'nullable|string|max:2000',
            'logo'        => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'website'     => 'nullable|url|max:150',
            'is_active'   => 'boolean',
            'sort_order'  => 'nullable|integer|min:0',
            'meta_data'   => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de la marque est requis.',
            'name.string' => 'Le nom de la marque doit être une chaîne de caractères.',
            'name.max' => 'Le nom de la marque ne peut pas dépasser 100 caractères.',
            'name.unique' => 'Une marque avec ce nom existe déjà.',
            'is_bylin_brand.boolean' => 'Le champ "is_bylin_brand" doit être un booléen.',
            'description.string' => 'La description doit être une chaîne de caractères.',
            'description.max' => 'La description ne peut pas dépasser 2000 caractères.',
            'logo.image' => 'Le logo doit être une image.',
            'logo.mimes' => 'Le logo doit être au format jpg, jpeg, png ou webp.',
            'logo.max' => 'Le logo ne peut pas dépasser 2 Mo.',
            'website.url' => 'Le site web doit être une URL valide.',
            'website.max' => 'Le site web ne peut pas dépasser 150 caractères.',
            'is_active.boolean' => 'Le champ "is_active" doit être un booléen.',
            'sort_order.integer' => 'Le champ "sort_order" doit être un entier.',
            'sort_order.min' => 'Le champ "sort_order" doit être supérieur ou égal à 0.',
            'meta_data.array' => 'Le champ "meta_data" doit être un tableau.',
        ];
    }
}
