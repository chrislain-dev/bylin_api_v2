<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.update') === true;
    }

    public function rules(): array
    {
        return [
            'order' => ['required', 'array', 'min:1', 'max:500'],
            'order.*.id' => ['required', 'uuid', 'distinct', 'exists:categories,id'],
            'order.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('categories') && ! $this->has('order')) {
            $this->merge([
                'order' => collect($this->input('categories', []))->map(fn (array $item) => [
                    'id' => $item['id'] ?? null,
                    'sort_order' => $item['sort_order'] ?? $item['order'] ?? null,
                ])->all(),
            ]);
        }
    }
}
