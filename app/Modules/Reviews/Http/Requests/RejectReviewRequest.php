<?php

declare(strict_types=1);

namespace Modules\Reviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reviews.manage') === true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
