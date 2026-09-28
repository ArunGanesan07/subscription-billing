<?php

namespace App\Services;

use App\Billing\InvoiceCalculator;
use App\Billing\SegmentTerms;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Merchant;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Support\CacheKeys;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Read model for GET /merchants/{id}/dashboard.
 *
 * Reads only the daily_usage rollup (never raw events), and projections run
 * through the same InvoiceCalculator that issues real invoices, so "projected"
 * and "billed" can never disagree about the maths. The result is cached for a
 * short TTL; it's an analytics view, and a minute of staleness is acceptable.
 */
class MerchantDashboard
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly PlanCatalog $plans,
        private readonly Cache $cache,
    ) {}

    public function build(Merchant $merchant, CarbonInterface $today): array
    {
        $today = CarbonImmutable::parse($today)->startOfDay();

        return $this->cache->remember(
            CacheKeys::dashboard($merchant->id, $today->toDateString()),
            config('billing.cache.dashboard_ttl'),
            fn () => $this->compute($merchant, $today),
        );
    }

    public function compute(Merchant $merchant, CarbonImmutable $today): array
    {
        $cycles = $this->currentCycles($merchant, $today);
        $currency = $merchant->currency;

        return [
            'merchant' => ['id' => $merchant->id, 'name' => $merchant->name, 'currency' => $currency],
            'as_of' => $today->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'current_cycle' => [
                'usage' => array_sum(array_column($cycles, 'usage')),
                'allowance' => array_sum(array_column($cycles, 'allowance')),
                'active_subscriptions' => count($cycles),
            ],
            'projected_overage_revenue' => Money::toArray(array_sum(array_column($cycles, 'projected_overage')), $currency) + [
                'method' => 'Per subscription: usage so far in the running plan segment, extrapolated linearly to the end of the cycle, priced with the invoice calculator.',
            ],
            'active_plans' => $this->activePlans($merchant, $cycles),
            'top_customers' => $this->topCustomers($merchant, $today, $cycles),
            'churn_risk' => $this->churnRisk($merchant, $today),
            'daily_trend' => $this->dailyTrend($merchant, $today),
            'system' => $this->systemStatus($merchant),
        ];
    }

    /**
     * Current-cycle usage, allowance and projected overage per active subscription.
     *
     * @return array<int, array{customer_id: int, plan_id: int, usage: int, allowance: int, projected_usage: int, projected_overage: int}>
     */
    private function currentCycles(Merchant $merchant, CarbonImmutable $today): array
    {
        $results = [];

        Subscription::query()
            ->ownedBy($merchant)
            ->where('status', SubscriptionStatus::Active)
            ->with('segments.plan')
            ->chunkById(500, function (EloquentCollection $subscriptions) use ($today, &$results) {
                $cycles = $subscriptions->mapWithKeys(fn (Subscription $s) => [$s->id => $s->billing_interval->cycleContaining($today)]);
                $earliest = $cycles->min(fn ($cycle) => $cycle->start);

                $usage = DailyUsage::query()->toBase()
                    ->whereIn('customer_id', $subscriptions->pluck('customer_id'))
                    ->whereBetween('usage_date', [$earliest->toDateString(), $today->toDateString()])
                    ->get(['customer_id', 'usage_date', 'units'])
                    ->groupBy('customer_id')
                    ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [substr((string) $r->usage_date, 0, 10) => (int) $r->units])->all());

                foreach ($subscriptions as $subscription) {
                    $cycle = $cycles[$subscription->id];
                    $segments = $subscription->segments
                        ->map(fn (SubscriptionSegment $segment) => SegmentTerms::fromModel($segment))
                        ->all();
                    $daily = $usage[$subscription->customer_id] ?? [];

                    $actual = $this->calculator->calculate($cycle, $segments, $daily);
                    $projected = $this->calculator->project($cycle, $segments, $daily, $today);

                    $results[$subscription->customer_id] = [
                        'customer_id' => $subscription->customer_id,
                        'plan_id' => $subscription->plan_id,
                        'usage' => $actual->usage(),
                        'allowance' => $actual->allowance(),
                        'projected_usage' => $projected->usage(),
                        'projected_overage' => $projected->overageTotal(),
                    ];
                }
            });

        return $results;
    }

    private function activePlans(Merchant $merchant, array $cycles): array
    {
        $counts = array_count_values(array_column($cycles, 'plan_id'));
        arsort($counts);

        return $this->plans->forMerchant($merchant->id)
            ->filter(fn ($plan) => isset($counts[$plan->id]))
            ->sortByDesc(fn ($plan) => $counts[$plan->id])
            ->map(fn ($plan) => [
                'plan_id' => $plan->id,
                'name' => $plan->name,
                'billing_interval' => $plan->billing_interval->value,
                'subscribers' => $counts[$plan->id],
            ])
            ->values()
            ->all();
    }

    /** Top customers by usage in the calendar month to date. */
    private function topCustomers(Merchant $merchant, CarbonImmutable $today, array $cycles): array
    {
        $rows = DailyUsage::query()->toBase()
            ->where('merchant_id', $merchant->id)
            ->whereBetween('usage_date', [$today->startOfMonth()->toDateString(), $today->toDateString()])
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(units) AS units')
            ->orderByDesc('units')
            ->limit(config('billing.dashboard.top_customers'))
            ->get();

        $names = Customer::query()->whereIn('id', $rows->pluck('customer_id'))->pluck('name', 'id');

        return $rows->map(function ($row) use ($names, $cycles) {
            $allowance = $cycles[$row->customer_id]['allowance'] ?? null;

            return [
                'customer_id' => (int) $row->customer_id,
                'name' => $names[$row->customer_id] ?? null,
                'usage' => (int) $row->units,
                'allowance' => $allowance,
                'percent_of_allowance' => $allowance ? round($row->units / $allowance * 100, 1) : null,
            ];
        })->all();
    }

    /**
     * Customers with an active subscription whose usage fell by more than the
     * threshold month-over-month. Compares complete days only (through
     * yesterday) against the same number of days at the start of the previous
     * month, so mid-month numbers aren't compared against a full month. On the
     * 1st, that's last month vs the month before.
     */
    private function churnRisk(Merchant $merchant, CarbonImmutable $today): array
    {
        $asOf = $today->subDay();
        $currentStart = $asOf->startOfMonth();
        $previousStart = $currentStart->subMonthNoOverflow();
        $previousEnd = $previousStart->addDays($asOf->day - 1)->min($previousStart->endOfMonth()->startOfDay());

        $threshold = config('billing.dashboard.churn_drop_threshold');
        $minBaseline = config('billing.dashboard.churn_min_baseline_units');

        $rows = DailyUsage::query()->toBase()
            ->where('merchant_id', $merchant->id)
            ->whereIn('customer_id', Subscription::query()
                ->select('customer_id')
                ->ownedBy($merchant)
                ->where('status', SubscriptionStatus::Active))
            ->where(fn ($q) => $q
                ->whereBetween('usage_date', [$previousStart->toDateString(), $previousEnd->toDateString()])
                ->orWhereBetween('usage_date', [$currentStart->toDateString(), $asOf->toDateString()]))
            ->groupBy('customer_id')
            ->selectRaw('customer_id')
            ->selectRaw('SUM(CASE WHEN usage_date >= ? THEN units ELSE 0 END) AS current_units', [$currentStart->toDateString()])
            ->selectRaw('SUM(CASE WHEN usage_date <= ? THEN units ELSE 0 END) AS previous_units', [$previousEnd->toDateString()])
            ->get()
            ->filter(fn ($r) => $r->previous_units >= $minBaseline && $r->current_units < $r->previous_units * (1 - $threshold));

        $names = Customer::query()->whereIn('id', $rows->pluck('customer_id'))->pluck('name', 'id');

        return [
            'window' => [
                'current' => ['start' => $currentStart->toDateString(), 'end' => $asOf->toDateString()],
                'previous' => ['start' => $previousStart->toDateString(), 'end' => $previousEnd->toDateString()],
            ],
            'customers' => $rows
                ->map(fn ($r) => [
                    'customer_id' => (int) $r->customer_id,
                    'name' => $names[$r->customer_id] ?? null,
                    'previous_usage' => (int) $r->previous_units,
                    'current_usage' => (int) $r->current_units,
                    'drop_percent' => round((1 - $r->current_units / $r->previous_units) * 100, 1),
                ])
                ->sortByDesc('drop_percent')
                ->values()
                ->all(),
        ];
    }

    private function dailyTrend(Merchant $merchant, CarbonImmutable $today): array
    {
        $from = $today->subDays(config('billing.dashboard.trend_days') - 1);

        $totals = DailyUsage::query()->toBase()
            ->where('merchant_id', $merchant->id)
            ->whereBetween('usage_date', [$from->toDateString(), $today->toDateString()])
            ->groupBy('usage_date')
            ->selectRaw('usage_date, SUM(units) AS units')
            ->pluck('units', 'usage_date')
            ->mapWithKeys(fn ($units, $date) => [substr((string) $date, 0, 10) => (int) $units]);

        $trend = [];
        for ($day = $from; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $trend[] = ['date' => $day->toDateString(), 'units' => $totals[$day->toDateString()] ?? 0];
        }

        return $trend;
    }

    private function systemStatus(Merchant $merchant): array
    {
        return [
            'cache_store' => config('cache.default'),
            'plan_cache_ttl_seconds' => config('billing.cache.plan_ttl'),
            'dashboard_cache_ttl_seconds' => config('billing.cache.dashboard_ttl'),
            'queue_connection' => config('queue.default'),
            'aggregation_chunk_size' => config('billing.aggregation.chunk_size'),
            'usage_rate_limit_per_minute' => config('billing.usage.rate_limit_per_minute'),
            'last_aggregation_run' => $this->cache->get(CacheKeys::lastAggregationRun()),
            'last_usage_recorded_at' => $this->cache->get(CacheKeys::lastUsageRecordedAt($merchant->id)),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }
}
