<?php

declare(strict_types=1);

namespace Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportCustomersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('customers.view') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'format' => 'nullable|in:csv,xlsx,pdf',
            'filters' => 'nullable|array',
            'filters.status' => 'nullable|in:active,inactive,suspended,banned',
            'filters.registered_from' => 'nullable|date',
            'filters.registered_to' => 'nullable|date|after_or_equal:filters.registered_from',
            'filters.has_orders' => 'nullable|boolean',
            'filters.search' => 'nullable|string|max:255',
            'columns' => 'nullable|array',
            'columns.*' => 'string|in:id,name,email,phone,status,total_orders,total_spent,registered_at',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'format.in' => 'Export format must be csv, xlsx, or pdf.',
            'filters.registered_to.after_or_equal' => 'End date must be after or equal to start date.',
        ];
    }

    /**
     * Get validated data with defaults.
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);
        
        // Set defaults
        $data['format'] = $data['format'] ?? 'csv';
        $data['columns'] = $data['columns'] ?? [
            'id', 'name', 'email', 'phone', 'status', 'total_orders', 'registered_at'
        ];
        
        return $data;
    }
}
