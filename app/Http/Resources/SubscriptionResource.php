<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $request->user()->currency;

        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'plan_id' => $this->plan_id,
            'status' => $this->status->value,
            'billing_interval' => $this->billing_interval->value,
            'started_on' => $this->started_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'billed_through' => $this->billed_through?->toDateString(),
            'segments' => $this->whenLoaded('segments', fn () => $this->segments->map(fn (SubscriptionSegment $segment) => [
                'plan_id' => $segment->plan_id,
                'plan_name' => $segment->plan?->name,
                'starts_on' => $segment->starts_on->toDateString(),
                'ends_on' => $segment->ends_on?->toDateString(),
                'base_price' => Money::toArray($segment->base_price, $currency),
                'included_units' => $segment->included_units,
                'overage_rate' => $segment->overage_rate,
            ])),
        ];
    }
}
