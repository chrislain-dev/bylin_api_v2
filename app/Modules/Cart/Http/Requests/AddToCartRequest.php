<?php

declare(strict_types=1);

namespace Modules\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'variation_id' => ['nullable', 'uuid', 'exists:product_variations,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'options' => ['nullable', 'array'],
        ];
    }
}
