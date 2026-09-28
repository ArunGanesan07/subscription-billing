<?php

namespace App\Services;

use App\Models\Plan;
use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * Cached read access to plans and pricing.
 *
 * Invalidation: PlanObserver calls forget() after the transaction that
 * changed a plan commits (never before, or a concurrent reader could re-cache
 * the old row). The TTL is only a safety net. Raw attribute arrays are cached,
 * not serialized models, so the cache never unserializes PHP objects.
 */
class PlanCatalog
{
    public function __construct(private readonly Cache $cache) {}

    public function find(int $planId): ?Plan
    {
        $attributes = $this->cache->remember(
            CacheKeys::plan($planId),
            $this->ttl(),
            fn () => Plan::query()->find($planId)?->getAttributes(),
        );

        return $attributes ? Plan::hydrate([$attributes])->first() : null;
    }

    /** A plan, only if it belongs to the given merchant. */
    public function findForMerchant(int $merchantId, int $planId): ?Plan
    {
        $plan = $this->find($planId);

        return $plan?->merchant_id === $merchantId ? $plan : null;
    }

    /**
     * @return Collection<int, Plan>
     */
    public function forMerchant(int $merchantId): Collection
    {
        $rows = $this->cache->remember(
            CacheKeys::merchantPlans($merchantId),
            $this->ttl(),
            fn () => Plan::query()
                ->where('merchant_id', $merchantId)
                ->orderBy('base_price')
                ->get()
                ->map(fn (Plan $plan) => $plan->getAttributes())
                ->all(),
        );

        return Plan::hydrate($rows);
    }

    public function forget(Plan $plan): void
    {
        $this->cache->forget(CacheKeys::plan($plan->id));
        $this->cache->forget(CacheKeys::merchantPlans($plan->merchant_id));
    }

    private function ttl(): int
    {
        return config('billing.cache.plan_ttl');
    }
}
