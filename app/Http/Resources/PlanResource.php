<?php

namespace App\Http\Resources;

use App\Models\Plan;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $request->user()->currency;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'billing_interval' => $this->billing_interval->value,
            'base_price' => Money::toArray($this->base_price, $currency),
            'included_units' => $this->included_units,
            'overage_rate' => [
                'amount' => $this->overage_rate,
                'currency' => $currency,
                'unit' => 'minor units per unit over allowance',
            ],
            'is_active' => $this->is_active,
        ];
    }
}
