<?php

namespace Tests\Concerns;

use App\Billing\Period;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

trait BuildsBillingFixtures
{
    protected function merchantWithKey(string $key = 'mk_test_primary_key'): Merchant
    {
        return Merchant::factory()->withApiKey($key)->create();
    }

    protected function auth(string $key = 'mk_test_primary_key'): array
    {
        return ['Authorization' => "Bearer {$key}", 'Accept' => 'application/json'];
    }

    /** Growth: ₹3,000 / month, 3,000 units, ₹0.50 per extra unit. */
    protected function growthPlan(Merchant $merchant, array $overrides = []): Plan
    {
        return Plan::factory()->for($merchant)->create(['code' => 'growth', 'name' => 'Growth'] + $overrides);
    }

    /** Scale: ₹9,000 / month, 10,000 units, ₹0.20 per extra unit. */
    protected function scalePlan(Merchant $merchant, array $overrides = []): Plan
    {
        return Plan::factory()->for($merchant)->create([
            'code' => 'scale',
            'name' => 'Scale',
            'base_price' => 900000,
            'included_units' => 10000,
            'overage_rate' => '20',
        ] + $overrides);
    }

    protected function subscribe(Customer $customer, Plan $plan, string $startsOn): Subscription
    {
        return app(SubscriptionService::class)->subscribe($customer, $plan, CarbonImmutable::parse($startsOn));
    }

    /** Writes raw usage events directly (bypasses the API) for billing tests. */
    protected function usage(Customer $customer, string $date, int $units, int $events = 1): void
    {
        for ($i = 0; $i < $events; $i++) {
            UsageEvent::query()->create([
                'merchant_id' => $customer->merchant_id,
                'customer_id' => $customer->id,
                'idempotency_key' => (string) Str::uuid(),
                'units' => intdiv($units, $events) + ($i === 0 ? $units % $events : 0),
                'usage_date' => $date,
            ]);
        }
    }

    /** $unitsPerDay on every day from $from to $to inclusive. */
    protected function dailyUsage(Customer $customer, string $from, string $to, int $unitsPerDay): void
    {
        foreach ((new Period($from, $to))->dates() as $date) {
            $this->usage($customer, $date, $unitsPerDay);
        }
    }
}
