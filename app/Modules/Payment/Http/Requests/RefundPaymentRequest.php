<?php

declare(strict_types=1);

namespace Modules\Payment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.refund') === true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Le montant est requis.',
            'amount.integer' => 'Le montant doit être un entier en plus petite unité monétaire.',
            'amount.min' => 'Le montant doit être supérieur à 0.',
            'reason.required' => 'Le motif du remboursement est requis.',
            'reason.max' => 'Le motif ne peut pas dépasser 500 caractères.',
        ];
    }

    public function attributes(): array
    {
        return [
            'amount' => 'montant',
            'reason' => 'motif',
        ];
    }
}
