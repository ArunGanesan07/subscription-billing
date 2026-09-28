<?php

namespace App\Http\Requests;

use App\Enums\BillingInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:50', 'alpha_dash',
                Rule::unique('plans')->where('merchant_id', $this->user()->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'billing_interval' => ['required', Rule::enum(BillingInterval::class)],
            'base_price' => ['required', 'integer', 'min:0'],
            'included_units' => ['required', 'integer', 'min:0'],
            'overage_rate' => ['required', 'numeric', 'min:0', 'decimal:0,6'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
