<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Subscription extends Model
{
    use BelongsToMerchant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'billing_interval' => BillingInterval::class,
            'status' => SubscriptionStatus::class,
            'started_on' => DateOnly::class,
            'ends_on' => DateOnly::class,
            'billed_through' => DateOnly::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(SubscriptionSegment::class)->orderBy('starts_on');
    }

    public function currentSegment(): HasOne
    {
        return $this->hasOne(SubscriptionSegment::class)->latestOfMany('starts_on');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }
}
