<?php

declare(strict_types=1);

namespace Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }


    protected function prepareForValidation(): void
    {
        if ($this->filled('country_code') && ! $this->filled('country')) {
            $this->merge([
                'country' => strtoupper((string) $this->input('country_code'))
            ]);
        }
    }


    public function rules(): array
    {
        return [
            'type' => [
                'required',
                'in:billing,shipping'
            ],

             'phone' => [
                'required',
                'string'
            ],

            'address_line_1' => [
                'required',
                'string',
                'max:255'
            ],

            'address_line_2' => [
                'nullable',
                'string',
                'max:255'
            ],

            'city' => [
                'required',
                'string',
                'max:100'
            ],

            'state' => [
                'nullable',
                'string',
                'max:100'
            ],

            'postal_code' => [
                'nullable',
                'string',
                'max:20'
            ],

            'country' => [
                'required',
                'string',
                'max:100'
            ],

            'is_default' => [
                'sometimes',
                'boolean'
            ],
        ];
    }


    public function messages(): array
    {
        return [

            'type.required' =>
                'Le type d’adresse est obligatoire.',

            'type.in' =>
                'Le type d’adresse doit être une adresse de livraison ou de facturation.',


            'address_line_1.required' =>
                'L’adresse est obligatoire.',

            'address_line_1.max' =>
                'L’adresse ne doit pas dépasser 255 caractères.',


            'address_line_2.max' =>
                'Le complément d’adresse ne doit pas dépasser 255 caractères.',


            'city.required' =>
                'La ville est obligatoire.',

            'city.max' =>
                'La ville ne doit pas dépasser 100 caractères.',


            'state.max' =>
                'La région ne doit pas dépasser 100 caractères.',


            'postal_code.max' =>
                'Le code postal ne doit pas dépasser 20 caractères.',


            'country.required' =>
                'Le pays est obligatoire.',

            'country.max' =>
                'Le pays ne doit pas dépasser 100 caractères.',


            'is_default.boolean' =>
                'La valeur adresse principale est invalide.',
        ];
    }


    public function attributes(): array
    {
        return [
            'type' => 'type d’adresse',
            'address_line_1' => 'adresse',
            'address_line_2' => 'complément d’adresse',
            'city' => 'ville',
            'state' => 'région',
            'postal_code' => 'code postal',
            'country' => 'pays',
            'is_default' => 'adresse principale',
        ];
    }
}
