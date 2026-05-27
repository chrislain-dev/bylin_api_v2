<?php

declare(strict_types=1);

namespace Modules\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContributeToGiftCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->input('name', $this->input('contributor_name')),
            'email' => $this->input('email', $this->input('contributor_email')),
        ]);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:500'],
            'is_anonymous' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Please enter a contribution amount.',
            'amount.min' => 'Contribution must be at least 1.',
            'name.required' => 'Please enter your name.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
        ];
    }
}
