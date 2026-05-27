<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderAttributesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.update') === true;
    }

    public function rules(): array
    {
        return [
            'orders' => ['required', 'array', 'min:1', 'max:500'],
            'orders.*.id' => ['required', 'uuid', 'distinct', 'exists:attributes,id'],
            'orders.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
