<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Services\PlanCatalog;
use App\Services\TenantLookup;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class PlanCacheTest extends TestCase
{
    use BuildsBillingFixtures, RefreshDatabase;

    #[Test]
    public function plan_lookups_are_served_from_cache_after_the_first_read(): void
    {
        $merchant = $this->merchantWithKey();
        $plan = $this->growthPlan($merchant);
        $catalog = app(PlanCatalog::class);

        $catalog->find($plan->id);
        $catalog->forMerchant($merchant->id);

        DB::enableQueryLog();
        $cached = $catalog->find($plan->id);
        $list = $catalog->forMerchant($merchant->id);

        $this->assertSame([], DB::getQueryLog(), 'Expected no queries on a warm cache.');
        $this->assertSame(300000, $cached->base_price);
        $this->assertSame([$plan->id], $list->pluck('id')->all());
    }

    #[Test]
    public function updating_a_plan_invalidates_its_cached_pricing(): void
    {
        $merchant = $this->merchantWithKey();
        $plan = $this->growthPlan($merchant);
        $catalog = app(PlanCatalog::class);

        $catalog->find($plan->id);
        $catalog->forMerchant($merchant->id);
        $this->assertTrue(Cache::has(CacheKeys::plan($plan->id)));

        $this->patchJson("/api/v1/plans/{$plan->id}", ['base_price' => 450000], $this->auth())->assertOk();

        $this->assertFalse(Cache::has(CacheKeys::plan($plan->id)));
        $this->assertFalse(Cache::has(CacheKeys::merchantPlans($merchant->id)));
        $this->assertSame(450000, $catalog->find($plan->id)->base_price);
        $this->assertSame(450000, $catalog->forMerchant($merchant->id)->first()->base_price);
    }

    #[Test]
    public function invalidation_waits_for_the_transaction_to_commit(): void
    {
        $merchant = $this->merchantWithKey();
        $plan = $this->growthPlan($merchant);
        $catalog = app(PlanCatalog::class);
        $catalog->find($plan->id);

        DB::transaction(function () use ($plan) {
            $plan->update(['base_price' => 1]);
            // Still cached mid-transaction: forgetting now would let a concurrent
            // reader re-cache the old committed row.
            $this->assertTrue(Cache::has(CacheKeys::plan($plan->id)));
        });

        $this->assertFalse(Cache::has(CacheKeys::plan($plan->id)));
    }

    #[Test]
    public function creating_a_plan_refreshes_the_merchant_catalog(): void
    {
        $merchant = $this->merchantWithKey();
        $this->growthPlan($merchant);
        app(PlanCatalog::class)->forMerchant($merchant->id);

        $this->postJson('/api/v1/plans', [
            'code' => 'scale', 'name' => 'Scale', 'billing_interval' => 'monthly',
            'base_price' => 900000, 'included_units' => 10000, 'overage_rate' => '20',
        ], $this->auth())->assertCreated();

        $this->getJson('/api/v1/plans', $this->auth())->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function rotating_an_api_key_revokes_the_old_one_immediately(): void
    {
        $merchant = $this->merchantWithKey('mk_test_old_key');
        $this->getJson('/api/v1/plans', $this->auth('mk_test_old_key'))->assertOk();   // warms the cache

        $fresh = Merchant::query()->find($merchant->id);
        $newKey = $fresh->issueApiKey();
        $fresh->save();

        $this->assertNull(app(TenantLookup::class)->merchantByApiKey('mk_test_old_key'));
        $this->getJson('/api/v1/plans', $this->auth('mk_test_old_key'))->assertUnauthorized();
        $this->getJson('/api/v1/plans', $this->auth($newKey))->assertOk();
    }
}
