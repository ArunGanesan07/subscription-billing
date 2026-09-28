<?php

namespace App\Observers;

use App\Models\Merchant;
use App\Services\TenantLookup;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class MerchantObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly TenantLookup $lookup) {}

    public function updated(Merchant $merchant): void
    {
        // Covers both profile edits and key rotation: after a rotation the old
        // key's cache entry must go, or the old key keeps working until the TTL.
        $this->lookup->forgetMerchantKey($merchant->getPrevious()['api_key_hash'] ?? $merchant->api_key_hash);
        $this->lookup->forgetMerchantKey($merchant->api_key_hash);
    }

    public function deleted(Merchant $merchant): void
    {
        $this->lookup->forgetMerchantKey($merchant->api_key_hash);
    }
}
