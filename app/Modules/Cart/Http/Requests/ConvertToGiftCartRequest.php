<?php

declare(strict_types=1);

namespace Modules\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConvertToGiftCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'cart_id' => ['required', 'uuid', 'exists:carts,id'],
            'message' => ['nullable', 'string', 'max:500'],
            'expiration_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    public function messages(): array
    {
        return [
            'cart_id.required' => 'The cart identifier is required.',
            'cart_id.exists' => 'The selected cart does not exist.',
            'expiration_days.max' => 'A gift cart cannot expire after more than 90 days.',
        ];
    }
}
