<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnablePreorderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.update') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'release_date' => 'required|date|after:today',
            'deposit_amount' => 'nullable|numeric|min:0',
            'max_quantity' => 'nullable|integer|min:1',
            'description' => 'nullable|string|max:1000',
            'terms' => 'nullable|string|max:2000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'release_date.after' => 'Release date must be in the future.',
            'deposit_amount.min' => 'Deposit amount must be 0 or greater.',
            'max_quantity.min' => 'Maximum quantity must be at least 1.',
        ];
    }

    /**
     * Get custom attribute names.
     */
    public function attributes(): array
    {
        return [
            'release_date' => 'estimated release date',
            'deposit_amount' => 'deposit amount',
            'max_quantity' => 'maximum preorder quantity',
        ];
    }
}
