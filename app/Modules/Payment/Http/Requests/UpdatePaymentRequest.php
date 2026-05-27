<?php

declare(strict_types=1);

namespace Modules\Payment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payment\Models\Payment;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.update') === true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in([
                Payment::STATUS_PENDING,
                Payment::STATUS_PROCESSING,
                Payment::STATUS_COMPLETED,
                Payment::STATUS_FAILED,
                Payment::STATUS_REFUNDED,
                Payment::STATUS_CANCELLED,
            ])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
