<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number(),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer->name),
            'subscription_id' => $this->subscription_id,
            'status' => $this->status->value,
            'period' => ['start' => $this->period_start->toDateString(), 'end' => $this->period_end->toDateString()],
            'total' => Money::toArray($this->total, $this->currency),
            'issued_at' => $this->issued_at->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (InvoiceLine $line) => [
                'type' => $line->type->value,
                'description' => $line->description,
                'plan_id' => $line->plan_id,
                'period' => ['start' => $line->period_start->toDateString(), 'end' => $line->period_end->toDateString()],
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'amount' => Money::toArray($line->amount, $this->currency),
                'meta' => $line->meta,
            ])),
        ];
    }
}
