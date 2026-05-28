<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage') === true;
    }

    public function rules(): array
    {
        return [
            'email_low_stock' => ['required', 'boolean'],
            'email_out_of_stock' => ['required', 'boolean'],
            'email_daily_summary' => ['required', 'boolean'],
            'push_low_stock' => ['required', 'boolean'],
            'push_out_of_stock' => ['required', 'boolean'],
            'default_low_stock_threshold' => ['required', 'integer', 'min:1', 'max:1000'],
            'alert_emails' => ['nullable', 'string', 'max:2000'],
            'alert_frequency' => ['required', Rule::in(['realtime', 'hourly', 'daily', 'weekly'])],
        ];
    }

    public function messages(): array
    {
        return [
            'default_low_stock_threshold.required' => 'Le seuil de stock faible est obligatoire.',
            'default_low_stock_threshold.integer' => 'Le seuil de stock faible doit être un nombre entier.',
            'default_low_stock_threshold.min' => 'Le seuil de stock faible doit être au moins égal à 1.',
            'default_low_stock_threshold.max' => 'Le seuil de stock faible ne peut pas dépasser 1000.',
            'alert_frequency.required' => 'La fréquence des alertes est obligatoire.',
            'alert_frequency.in' => 'La fréquence des alertes est invalide.',
            'alert_emails.max' => 'La liste des emails ne peut pas dépasser 2000 caractères.',
        ];
    }
}
