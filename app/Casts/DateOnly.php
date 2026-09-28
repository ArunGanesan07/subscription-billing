<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar date stored as 'Y-m-d' on every driver.
 *
 * Laravel's built-in `date` cast stores 'Y-m-d 00:00:00'. MySQL's DATE type
 * truncates that, but SQLite keeps the string, so a range filter like
 * BETWEEN '2026-09-01' AND '2026-09-30' silently drops the last day. Billing
 * compares dates everywhere, so we store exactly what we mean.
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toDateString();
    }
}
