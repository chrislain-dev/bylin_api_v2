<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteCategoriesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.delete') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'category_ids' => 'required|array|min:1|max:100',
            'category_ids.*' => 'required|uuid|exists:categories,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'category_ids.required' => 'Please select at least one category.',
            'category_ids.min' => 'Please select at least one category.',
            'category_ids.max' => 'You cannot delete more than 100 categories at once.',
            'category_ids.*.exists' => 'One or more selected categories do not exist.',
        ];
    }
}
