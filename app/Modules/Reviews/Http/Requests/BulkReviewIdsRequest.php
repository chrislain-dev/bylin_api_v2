<?php

declare(strict_types=1);

namespace Modules\Reviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkReviewIdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reviews.manage') === true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'uuid', 'distinct', 'exists:reviews,id'],
        ];
    }
}
