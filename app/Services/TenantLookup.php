<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Merchant;
use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Cached lookups on the POST /usage hot path, so recording an event costs
 * one INSERT and no reads once the cache is warm.
 *
 * Only positive results are cached: a miss (unknown key/customer) always goes
 * to the database, so a record created a moment ago is never hidden by a
 * cached "not found". Rate limiting bounds the cost of repeated misses.
 */
class TenantLookup
{
    public function __construct(private readonly Cache $cache) {}

    public function merchantByApiKey(string $plainKey): ?Merchant
    {
        $hash = Merchant::hashApiKey($plainKey);
        $key = CacheKeys::merchantByApiKeyHash($hash);

        $attributes = $this->cache->get($key);

        if ($attributes === null) {
            $attributes = Merchant::query()->where('api_key_hash', $hash)->first()?->getAttributes();

            if ($attributes === null) {
                return null;
            }

            $this->cache->put($key, $attributes, $this->ttl());
        }

        return Merchant::hydrate([$attributes])->first();
    }

    /** The owning merchant's ID, or null if the customer doesn't exist. */
    public function customerMerchantId(int $customerId): ?int
    {
        $key = CacheKeys::customerOwner($customerId);
        $merchantId = $this->cache->get($key);

        if ($merchantId === null) {
            $merchantId = Customer::query()->whereKey($customerId)->value('merchant_id');

            if ($merchantId === null) {
                return null;
            }

            $this->cache->put($key, $merchantId, $this->ttl());
        }

        return (int) $merchantId;
    }

    public function forgetMerchantKey(string $apiKeyHash): void
    {
        $this->cache->forget(CacheKeys::merchantByApiKeyHash($apiKeyHash));
    }

    public function forgetCustomer(int $customerId): void
    {
        $this->cache->forget(CacheKeys::customerOwner($customerId));
    }

    private function ttl(): int
    {
        return config('billing.cache.lookup_ttl');
    }
}
