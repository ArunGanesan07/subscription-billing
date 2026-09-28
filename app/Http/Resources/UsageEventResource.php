<?php

namespace App\Http\Resources;

use App\Models\UsageEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UsageEvent */
class UsageEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'units' => $this->units,
            'usage_date' => $this->usage_date->toDateString(),
            'idempotency_key' => $this->idempotency_key,
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
