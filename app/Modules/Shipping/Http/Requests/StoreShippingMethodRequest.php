<?php

declare(strict_types=1);

namespace Modules\Shipping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShippingMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shipping.create') === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:shipping_methods,code'],
            'description' => ['nullable', 'string', 'max:1000'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'base_cost' => ['required', 'numeric', 'min:0'],
            'cost_per_kg' => ['nullable', 'numeric', 'min:0'],
            'cost_per_km' => ['nullable', 'numeric', 'min:0'],
            'free_shipping_threshold' => ['nullable', 'numeric', 'min:0'],
            'estimated_delivery_days' => ['nullable', 'integer', 'min:0'],
            'estimated_days_min' => ['nullable', 'integer', 'min:0'],
            'estimated_days_max' => ['nullable', 'integer', 'min:0', 'gte:estimated_days_min'],
            'rate_calculation' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'available_countries' => ['nullable', 'array'],
            'available_countries.*' => ['string', 'size:2'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This shipping method code is already in use.',
            'estimated_days_max.gte' => 'Maximum delivery days must be greater than or equal to minimum days.',
            'available_countries.*.size' => 'Country codes must be 2-letter ISO codes.',
        ];
    }
}
