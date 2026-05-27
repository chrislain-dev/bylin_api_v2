<?php

declare(strict_types=1);

namespace Modules\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('coupon_code')) {
            $this->merge(['coupon_code' => strtoupper(trim((string) $this->input('coupon_code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'coupon_code' => ['required', 'string', 'max:50'],
        ];
    }
}
