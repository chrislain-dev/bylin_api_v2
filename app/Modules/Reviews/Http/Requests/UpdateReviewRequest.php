<?php

declare(strict_types=1);

namespace Modules\Reviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'rating' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rating.min' => 'La note minimale est 1.',
            'rating.max' => 'La note maximale est 5.',
            'title.max' => 'Le titre ne peut pas dépasser 255 caractères.',
            'comment.max' => 'Le commentaire ne peut pas dépasser 5000 caractères.',
        ];
    }
}
