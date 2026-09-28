<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The billing interval and code are immutable: changing the interval of a plan
 * that has subscribers would change the meaning of their current cycle.
 * Price edits apply to new subscriptions and plan changes only; existing
 * segments keep their snapshot (see README).
 */
class UpdatePlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'base_price' => ['sometimes', 'integer', 'min:0'],
            'included_units' => ['sometimes', 'integer', 'min:0'],
            'overage_rate' => ['sometimes', 'numeric', 'min:0', 'decimal:0,6'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
