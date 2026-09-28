<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'external_id' => [
                'nullable', 'string', 'max:100',
                Rule::unique('customers')->where('merchant_id', $this->user()->id),
            ],
        ];
    }
}
