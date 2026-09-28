<?php

namespace App\Models\Concerns;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant scoping for models owned by a merchant.
 *
 * Route model binding only resolves records of the authenticated merchant, so
 * another tenant's record is a 404, indistinguishable from a missing one; we
 * never confirm that someone else's ID exists.
 */
trait BelongsToMerchant
{
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function scopeOwnedBy(Builder $query, Merchant|int $merchant): Builder
    {
        return $query->where(
            $this->qualifyColumn('merchant_id'),
            $merchant instanceof Merchant ? $merchant->id : $merchant,
        );
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $merchant = request()->user();

        if (! $merchant instanceof Merchant) {
            return null;
        }

        return $this->newQuery()
            ->ownedBy($merchant)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
