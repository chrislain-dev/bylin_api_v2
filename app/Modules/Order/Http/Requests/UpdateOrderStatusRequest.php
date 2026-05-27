<?php

declare(strict_types=1);

namespace Modules\Order\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Order\Models\Order;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('orders.update') === true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    Order::STATUS_PENDING,
                    Order::STATUS_PROCESSING,
                    Order::STATUS_CONFIRMED,
                    Order::STATUS_SHIPPED,
                    Order::STATUS_DELIVERED,
                    Order::STATUS_CANCELLED,
                    Order::STATUS_REFUNDED,
                ]),
            ],
            'note' => ['nullable', 'string', 'max:500'],
            'notify_customer' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Le statut de la commande est requis.',
            'status.in' => 'Le statut sélectionné est invalide.',
            'note.max' => 'La note ne peut pas dépasser 500 caractères.',
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => 'statut',
            'note' => 'note',
            'notify_customer' => 'notifier le client',
        ];
    }
}
