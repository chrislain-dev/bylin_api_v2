<?php

declare(strict_types=1);

namespace Modules\Order\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payment\Models\Payment;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'shipping_address' => ['required', 'array'],
            'shipping_address.first_name' => ['required', 'string', 'max:100'],
            'shipping_address.last_name' => ['required', 'string', 'max:100'],
            'shipping_address.address_line1' => ['required', 'string', 'max:255'],
            'shipping_address.address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:100'],
            'shipping_address.state' => ['nullable', 'string', 'max:100'],
            'shipping_address.country' => ['nullable', 'string', 'size:2'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:30'],
            'shipping_address.phone' => ['required', 'string', 'max:30'],

            'billing_address' => ['nullable', 'array'],
            'billing_address.first_name' => ['nullable', 'string', 'max:100'],
            'billing_address.last_name' => ['nullable', 'string', 'max:100'],
            'billing_address.address_line1' => ['nullable', 'string', 'max:255'],
            'billing_address.address_line2' => ['nullable', 'string', 'max:255'],
            'billing_address.city' => ['nullable', 'string', 'max:100'],
            'billing_address.state' => ['nullable', 'string', 'max:100'],
            'billing_address.country' => ['nullable', 'string', 'size:2'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:30'],
            'billing_address.phone' => ['nullable', 'string', 'max:30'],

            'payment_method' => ['required', 'string', Rule::in([
                Payment::GATEWAY_FEDAPAY,
                Payment::GATEWAY_CASH,
                Payment::GATEWAY_MOBILE_MONEY,
                'card',
            ])],
            'customer_email' => ['required', 'email:rfc,dns', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_note' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
