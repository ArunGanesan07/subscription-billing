<?php

namespace Database\Seeders;

use App\Billing\Period;
use App\Enums\BillingInterval;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\InvoiceGenerator;
use App\Services\SubscriptionService;
use App\Services\UsageAggregator;
use App\Support\CacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Realistic demo data relative to today: ~4 months of usage for two tenants,
 * mid-cycle starts, an upgrade and a downgrade, two customers in decline
 * (churn risk), a few heavy users in overage, and issued invoices for every
 * settled cycle. Deterministic (fixed random seed) so demos are repeatable.
 */
class DemoSeeder extends Seeder
{
    public const ACME_KEY = 'mk_demo_acme_corp_local_only_do_not_use_in_prod';

    public const GLOBEX_KEY = 'mk_demo_globex_local_only_do_not_use_in_prod';

    private CarbonImmutable $today;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    public function run(SubscriptionService $subscriptions, UsageAggregator $aggregator, InvoiceGenerator $invoices): void
    {
        // IDs restart after migrate:fresh; drop cached lookups/dashboards from any previous seed.
        Cache::flush();

        mt_srand(42);
        $this->today = CarbonImmutable::today();
        $historyStart = $this->today->subMonthsNoOverflow(4)->startOfMonth();

        $acme = $this->merchant('Acme Corp', self::ACME_KEY);
        $plans = $this->plans($acme);

        // name => [plan, start (months ago, day), daily usage as a fraction of the plan's daily allowance]
        $roster = [
            'Beta Retail Pvt Ltd' => ['growth', [4, 1], 1.30],
            'Craft Foods Co.' => ['growth', [3, 12], 0.95],
            'Delta Mart' => ['scale', [4, 1], 0.55],
            'Nova Traders' => ['growth', [4, 1], 0.80],
            'QuickMart' => ['growth', [3, 5], 0.75],
            'Zenith Logistics' => ['scale', [4, 1], 1.15],
            'Orbit Pharma' => ['growth', [2, 18], 1.25],
            'Lotus Textiles' => ['starter', [4, 1], 1.35],
            'Kaveri Agro' => ['starter', [3, 1], 0.70],
            'Saffron Hotels' => ['growth', [4, 1], 0.45],
            'Indigo Apparel' => ['starter', [2, 1], 0.90],
            'Banyan Health' => ['scale', [3, 20], 0.40],
            'Monsoon Travels' => ['starter', [4, 1], 0.50],
            'Himalaya Organics' => ['growth', [1, 7], 0.65],
            'Metro Electricals' => ['starter', [2, 9], 1.20],
            'Sunrise Bakers' => ['starter', [4, 1], 0.30],
            'Garuda Couriers' => ['enterprise', [4, 1], 0.85],
            'Mysore Coffee Works' => ['starter', [0, 0], 0.80],   // joined this month (prorated)
        ];

        $customers = [];
        foreach ($roster as $name => [$planCode, [$monthsAgo, $day], $intensity]) {
            $startsOn = $monthsAgo === 0
                ? $this->today->subDays(min(6, $this->today->day - 1))
                : $this->today->subMonthsNoOverflow($monthsAgo)->startOfMonth()->addDays($day - 1);

            $customer = $acme->customers()->create([
                'name' => $name,
                'external_id' => 'acme-'.str($name)->slug(),
                'email' => 'billing@'.str($name)->slug().'.example',
            ]);

            $subscription = $subscriptions->subscribe($customer, $plans[$planCode], $startsOn);
            $customers[$name] = [$customer, $subscription, $plans[$planCode], $intensity, $startsOn];
        }

        // Plan changes (in date order, as they'd really happen).
        $lastMonthMid = $this->today->subMonthNoOverflow()->startOfMonth()->addDays(14);
        $this->changePlan($subscriptions, $customers, 'Beta Retail Pvt Ltd', $plans['scale'], $lastMonthMid);   // upgrade
        $this->changePlan($subscriptions, $customers, 'Delta Mart', $plans['growth'], $lastMonthMid);           // downgrade
        if ($this->today->day > 3) {
            $this->changePlan($subscriptions, $customers, 'Craft Foods Co.', $plans['scale'], $this->today->startOfMonth()->addDays(2));
        }

        foreach ($customers as $name => [$customer, $subscription, $plan, $intensity, $startsOn]) {
            $this->generateUsage($customer, $subscription->fresh('segments'), $intensity, $startsOn, $name);
        }

        // A second tenant, to show isolation.
        $globex = $this->merchant('Globex Ltd', self::GLOBEX_KEY);
        $globexPlans = $this->plans($globex);
        foreach (['Initech', 'Umbrella Pharma', 'Hooli Retail'] as $name) {
            $customer = $globex->customers()->create(['name' => $name, 'external_id' => 'globex-'.str($name)->slug()]);
            $startsOn = $historyStart;
            $subscription = $subscriptions->subscribe($customer, $globexPlans['growth'], $startsOn);
            $this->generateUsage($customer, $subscription->fresh('segments'), 0.8, $startsOn, $name);
        }

        $this->flushEvents();

        // Roll everything up and issue every invoice that's due (what the
        // nightly `billing:run` would have done over these months).
        $aggregator->aggregate(Customer::query()->pluck('id')->all(), new Period($historyStart, $this->today));
        Subscription::query()->each(fn (Subscription $s) => $invoices->generateDue($s, $this->today));

        Cache::forever(CacheKeys::lastAggregationRun(), [
            'batch_id' => null,
            'name' => 'demo seed',
            'as_of' => $this->today->toDateString(),
            'finished_at' => now()->toIso8601String(),
            'total_jobs' => 0,
            'failed_jobs' => 0,
        ]);
        Cache::put(CacheKeys::lastUsageRecordedAt($acme->id), now()->toIso8601String(), now()->addDays(7));

        $this->report($acme, $globex);
    }

    private function merchant(string $name, string $key): Merchant
    {
        $merchant = new Merchant(['name' => $name, 'slug' => str($name)->slug(), 'currency' => 'INR']);
        $merchant->issueApiKey($key);
        $merchant->save();

        return $merchant;
    }

    /** @return array<string, Plan> */
    private function plans(Merchant $merchant): array
    {
        $catalog = [
            // code => [name, interval, base (paise), included units, overage (paise/unit)]
            'starter' => ['Starter', BillingInterval::Monthly, 99900, 5000, '50'],
            'growth' => ['Growth', BillingInterval::Monthly, 499900, 50000, '25'],
            'scale' => ['Scale', BillingInterval::Monthly, 1499900, 250000, '12.5'],
            'enterprise' => ['Enterprise Annual', BillingInterval::Yearly, 14999900, 3000000, '8'],
        ];

        $plans = [];
        foreach ($catalog as $code => [$name, $interval, $base, $included, $rate]) {
            $plans[$code] = $merchant->plans()->create([
                'code' => $code,
                'name' => $name,
                'billing_interval' => $interval,
                'base_price' => $base,
                'included_units' => $included,
                'overage_rate' => $rate,
            ]);
        }

        return $plans;
    }

    private function changePlan(SubscriptionService $service, array &$customers, string $name, Plan $plan, CarbonImmutable $on): void
    {
        [$customer, $subscription] = $customers[$name];
        $customers[$name][1] = $service->changePlan($subscription->fresh(), $plan, $on);
    }

    /**
     * Daily usage follows the plan in force that day (so upgrades show a step
     * up), with weekday/weekend seasonality and noise. Two customers decline
     * sharply this month to exercise the churn-risk panel.
     */
    private function generateUsage(Customer $customer, Subscription $subscription, float $intensity, CarbonImmutable $from, string $name): void
    {
        $decline = ['Nova Traders' => 0.38, 'QuickMart' => 0.45][$name] ?? 1.0;
        $monthStart = $this->today->startOfMonth();

        foreach ((new Period($from, $this->today))->dates() as $date) {
            $day = CarbonImmutable::parse($date);
            $segment = $subscription->segments->last(fn ($s) => $s->starts_on->lessThanOrEqualTo($day));
            $cycleDays = $subscription->billing_interval->cycleContaining($day)->days();

            $units = $segment->included_units / $cycleDays * $intensity
                * ($day->isWeekend() ? 0.6 : 1.1)
                * (mt_rand(80, 120) / 100)
                * ($day->greaterThanOrEqualTo($monthStart) ? $decline : 1.0);

            if ($day->isToday()) {
                $units *= 0.4;   // today is still in progress
            }

            $units = max(1, (int) round($units));
            $events = mt_rand(2, 4);

            for ($i = 0; $i < $events; $i++) {
                $this->events[] = [
                    'merchant_id' => $customer->merchant_id,
                    'customer_id' => $customer->id,
                    'idempotency_key' => "seed-{$customer->id}-{$date}-{$i}",
                    'units' => max(1, intdiv($units, $events) + ($i === 0 ? $units % $events : 0)),
                    'usage_date' => $date,
                    'created_at' => $day->setTime(mt_rand(0, 23), mt_rand(0, 59)),
                ];
            }

            if (count($this->events) >= 2000) {
                $this->flushEvents();
            }
        }
    }

    private function flushEvents(): void
    {
        foreach (array_chunk($this->events, 500) as $chunk) {
            DB::table('usage_events')->insert($chunk);
        }

        $this->events = [];
    }

    private function report(Merchant $acme, Merchant $globex): void
    {
        $this->command?->newLine();
        $this->command?->info('Demo data ready.');
        $this->command?->table(['Merchant', 'ID', 'API key (local demo only)'], [
            [$acme->name, $acme->id, self::ACME_KEY],
            [$globex->name, $globex->id, self::GLOBEX_KEY],
        ]);
        $this->command?->line('  Usage events: '.number_format(DB::table('usage_events')->count())
            .' · daily rows: '.number_format(DB::table('daily_usage')->count())
            .' · invoices: '.number_format(DB::table('invoices')->count()));
        $this->command?->newLine();
        $this->command?->line('  Dashboard: '.rtrim(config('app.url'), '/')."/dashboard#merchant={$acme->id}&key=".self::ACME_KEY);
        $this->command?->newLine();
    }
}
