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
            'description' => 'nullable|string|max:2000',
            'logo'        => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'website'     => 'nullable|url|max:150',
            'is_active'   => 'boolean',
            'sort_order'  => 'nullable|integer|min:0',
            'meta_data'   => 'nullable|array',
        ];
    }
}
