<?php

declare(strict_types=1);

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogue.update') === true;
    }

    public function rules(): array
    {
        $categoryId = (string) $this->route('id');

        return [
            'parent_id' => [
                'nullable',
                'uuid',
                'exists:categories,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($categoryId): void {
                    if ($value !== null && $value === $categoryId) {
                        $fail('Une catégorie ne peut pas être son propre parent.');
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('new_parent_id') && ! $this->has('parent_id')) {
            $this->merge(['parent_id' => $this->input('new_parent_id')]);
        }
    }
}
