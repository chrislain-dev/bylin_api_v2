<?php

declare(strict_types=1);

namespace Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateCustomerStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('customers.update') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'required|uuid|distinct|exists:customers,id',
            'status' => 'required|in:active,inactive,suspended',
            'reason' => 'nullable|string|max:500',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Veuillez sélectionner au moins un client.',
            'ids.min' => 'Veuillez sélectionner au moins un client.',
            'ids.max' => 'Vous ne pouvez pas mettre à jour plus de 500 clients à la fois.',
            'ids.*.exists' => 'Un ou plusieurs clients sélectionnés n\'existent pas.',
            'status.in' => 'Le statut doit être: active, inactive, ou suspended.',
        ];
    }
}
