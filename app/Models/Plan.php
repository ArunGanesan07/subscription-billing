<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Models\Concerns\BelongsToMerchant;
use App\Observers\PlanObserver;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(PlanObserver::class)]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use BelongsToMerchant, HasFactory;

    // Mirrors the column default so a freshly created model reports it too.
    protected $attributes = ['is_active' => true];

    protected $fillable = [
        'code', 'name', 'billing_interval', 'base_price', 'included_units', 'overage_rate', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'billing_interval' => BillingInterval::class,
            'base_price' => 'integer',
            'included_units' => 'integer',
            'overage_rate' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }
}
