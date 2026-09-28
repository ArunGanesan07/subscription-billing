<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable raw usage record. Never updated or deleted by the application.
 */
class UsageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['merchant_id', 'customer_id', 'idempotency_key', 'units', 'usage_date'];

    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'customer_id' => 'integer',
            'units' => 'integer',
            'usage_date' => DateOnly::class,
        ];
    }

    /** True if this stored event carries the same payload as a (retried) request. */
    public function matches(int $customerId, int $units, string $usageDate): bool
    {
        return $this->customer_id === $customerId
            && $this->units === $units
            && $this->usage_date->toDateString() === $usageDate;
    }
}
