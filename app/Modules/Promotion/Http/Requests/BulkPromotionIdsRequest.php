<?php

declare(strict_types=1);

namespace Modules\Promotion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkPromotionIdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->requiredPermission()) === true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'uuid', 'distinct', 'exists:promotions,id'],
        ];
    }

    private function requiredPermission(): string
    {
        $routeName = (string) $this->route()?->getName();

        return str_contains($routeName, 'destroy')
            ? 'promotions.delete'
            : 'promotions.update';
    }
}
