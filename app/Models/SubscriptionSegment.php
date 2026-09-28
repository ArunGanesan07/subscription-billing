<?php

namespace App\Models;

use App\Billing\Period;
use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionSegment extends Model
{
    protected $fillable = ['starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'starts_on' => DateOnly::class,
            'ends_on' => DateOnly::class,
            'base_price' => 'integer',
            'included_units' => 'integer',
            'overage_rate' => 'decimal:6',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function scopeOverlapping(Builder $query, Period $period): Builder
    {
        return $query
            ->where('starts_on', '<=', $period->end->toDateString())
            ->where(fn (Builder $q) => $q
                ->whereNull('ends_on')
                ->orWhere('ends_on', '>=', $period->start->toDateString()));
    }

    /** Copies the plan's current pricing onto this segment. */
    public function snapshotPricing(Plan $plan): static
    {
        $this->plan_id = $plan->id;
        $this->base_price = $plan->base_price;
        $this->included_units = $plan->included_units;
        $this->overage_rate = $plan->overage_rate;

        return $this;
    }
}
