<?php

declare(strict_types=1);

namespace Modules\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShippingMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shipping.update') === true;
    }

    public function rules(): array
    {
        $shippingMethodId = $this->route('shipping_method') ?? $this->route('shipping-method') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('shipping_methods', 'code')->ignore($shippingMethodId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'base_cost' => ['sometimes', 'numeric', 'min:0'],
            'cost_per_kg' => ['nullable', 'numeric', 'min:0'],
            'cost_per_km' => ['nullable', 'numeric', 'min:0'],
            'free_shipping_threshold' => ['nullable', 'numeric', 'min:0'],
            'estimated_delivery_days' => ['nullable', 'integer', 'min:0'],
            'min_delivery_days' => ['nullable', 'integer', 'min:0'],
            'max_delivery_days' => ['nullable', 'integer', 'min:0', 'gte:min_delivery_days'],
            'rate_calculation' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'zones' => ['nullable', 'array'],
            'zones.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This shipping method code is already in use.',
            'max_delivery_days.gte' => 'Maximum delivery days must be greater than or equal to minimum.',
        ];
    }
}
