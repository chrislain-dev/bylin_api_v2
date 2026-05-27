<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkAsFakeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('authenticity.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:500',
            'reported_location' => 'nullable|string|max:255',
            'reported_seller' => 'nullable|string|max:255',
            'additional_notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Please provide a reason for marking this code as fake.',
            'reason.max' => 'Reason cannot exceed 500 characters.',
        ];
    }
}
