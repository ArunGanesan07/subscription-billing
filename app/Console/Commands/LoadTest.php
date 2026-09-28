<?php

namespace App\Console\Commands;

use App\Billing\Period;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Merchant;
use App\Models\Subscription;
use App\Services\InvoiceGenerator;
use App\Services\MerchantDashboard;
use App\Services\UsageAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk-loads synthetic usage and times the queries that matter at 50L+ rows.
 * Use a throwaway database:
 *   DB_DATABASE=database/loadtest.sqlite php artisan migrate:fresh --force
 *   DB_DATABASE=database/loadtest.sqlite php artisan usage:load-test --rows=5000000
 */
class LoadTest extends Command
{
    protected $signature = 'usage:load-test
        {--rows=5000000 : usage_events rows to insert}
        {--customers=2000}
        {--days=90}';

    protected $description = 'Load synthetic usage events and time aggregation, invoicing and dashboard queries';

    public function handle(UsageAggregator $aggregator, InvoiceGenerator $invoices, MerchantDashboard $dashboard): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $rows = (int) $this->option('rows');
        $customerCount = (int) $this->option('customers');
        $days = (int) $this->option('days');
        $today = CarbonImmutable::today();
        $from = $today->subDays($days - 1);

        $merchant = $this->setUpTenant($customerCount, $from);
        $customerIds = $merchant->customers()->pluck('id')->all();

        // ---- Ingest
        $this->info('Inserting '.number_format($rows).' usage events…');
        $bar = $this->output->createProgressBar($rows);
        $start = microtime(true);
        $batch = [];
        $dates = (new Period($from, $today))->dates();
        $now = now()->toDateTimeString();

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('PRAGMA journal_mode = WAL');
        }

        for ($i = 0; $i < $rows; $i++) {
            $batch[] = [
                'merchant_id' => $merchant->id,
                'customer_id' => $customerIds[$i % $customerCount],
                'idempotency_key' => 'lt-'.$i,
                'units' => mt_rand(1, 50),
                'usage_date' => $dates[intdiv($i, $customerCount) % count($dates)],
                'created_at' => $now,
            ];

            if (count($batch) === 4000) {
                DB::table('usage_events')->insert($batch);
                $bar->advance(count($batch));
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('usage_events')->insert($batch);
            $bar->advance(count($batch));
        }
        $bar->finish();
        $this->newLine();
        $ingest = microtime(true) - $start;

        $results = [
            ['Bulk insert (batched, not the API path)', $this->ms($ingest), number_format($rows / $ingest).' rows/s'],
        ];

        // ---- API-path inserts: one row each, maintaining the unique + covering indexes
        $start = microtime(true);
        for ($i = 0; $i < 200; $i++) {
            DB::table('usage_events')->insert([
                'merchant_id' => $merchant->id, 'customer_id' => $customerIds[$i % $customerCount], 'idempotency_key' => (string) Str::uuid(),
                'units' => 1, 'usage_date' => $today->toDateString(), 'created_at' => $now,
            ]);
        }
        $results[] = ['Single-row idempotent insert (avg of 200)', $this->ms((microtime(true) - $start) / 200), 'on a '.number_format($rows).'-row table, autocommit'];

        // ---- Nightly job: one chunk (500 customers × 3-day window)
        $chunk = array_slice($customerIds, 0, config('billing.aggregation.chunk_size'));
        $results[] = $this->time('Nightly chunk: 500 customers × 3 days', fn () => $aggregator->aggregate($chunk, new Period($today->subDays(2), $today)));

        // ---- Full backfill: everything, in chunks
        $results[] = $this->time("Full backfill: {$customerCount} customers × {$days} days", function () use ($aggregator, $customerIds, $from, $today) {
            foreach (array_chunk($customerIds, config('billing.aggregation.chunk_size')) as $ids) {
                $aggregator->aggregate($ids, new Period($from, $today));
            }
        });

        // ---- Invoice: one customer's cycle (re-aggregates from raw first)
        $subscription = Subscription::query()->where('customer_id', $customerIds[1])->first();
        $cycle = BillingInterval::Monthly->cycleContaining($today->subMonthNoOverflow());
        $results[] = $this->time('Generate one invoice (incl. re-aggregation)', fn () => $invoices->generate($subscription, $cycle));

        // ---- Dashboard (uncached compute)
        $results[] = $this->time("Dashboard compute ({$customerCount} active subs, uncached)", fn () => $dashboard->compute($merchant, $today));

        $this->newLine();
        $this->table(['Operation', 'Time', 'Notes'], $results);

        $this->line('Aggregation query plan:');
        foreach (DB::select('EXPLAIN QUERY PLAN SELECT customer_id, usage_date, SUM(units), COUNT(*) FROM usage_events WHERE customer_id IN (1,2,3) AND usage_date BETWEEN ? AND ? GROUP BY customer_id, usage_date', [$from->toDateString(), $today->toDateString()]) as $row) {
            $this->line('  '.$row->detail);
        }

        return self::SUCCESS;
    }

    private function setUpTenant(int $customerCount, CarbonImmutable $from): Merchant
    {
        $merchant = new Merchant(['name' => 'Load Test Co', 'slug' => 'load-test-'.Str::lower(Str::random(5)), 'currency' => 'INR']);
        $merchant->issueApiKey();
        $merchant->save();

        $plan = $merchant->plans()->create([
            'code' => 'lt', 'name' => 'Load', 'billing_interval' => BillingInterval::Monthly,
            'base_price' => 100000, 'included_units' => 20000, 'overage_rate' => '10',
        ]);

        $now = now();
        foreach (array_chunk(range(1, $customerCount), 500) as $chunk) {
            DB::table('customers')->insert(array_map(fn ($n) => [
                'merchant_id' => $merchant->id, 'name' => "LT Customer {$n}", 'external_id' => "lt-{$merchant->id}-{$n}",
                'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        $customerIds = $merchant->customers()->pluck('id');
        foreach ($customerIds->chunk(500) as $ids) {
            DB::table('subscriptions')->insert($ids->map(fn ($id) => [
                'merchant_id' => $merchant->id, 'customer_id' => $id, 'plan_id' => $plan->id,
                'billing_interval' => 'monthly', 'status' => SubscriptionStatus::Active->value,
                'started_on' => $from->startOfMonth()->toDateString(), 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }

        $subscriptionIds = Subscription::query()->where('merchant_id', $merchant->id)->pluck('id');
        foreach ($subscriptionIds->chunk(500) as $ids) {
            DB::table('subscription_segments')->insert($ids->map(fn ($id) => [
                'subscription_id' => $id, 'plan_id' => $plan->id, 'starts_on' => $from->startOfMonth()->toDateString(),
                'base_price' => $plan->base_price, 'included_units' => $plan->included_units, 'overage_rate' => $plan->overage_rate,
                'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }

        return $merchant;
    }

    private function time(string $label, callable $fn): array
    {
        $start = microtime(true);
        $fn();

        return [$label, $this->ms(microtime(true) - $start), ''];
    }

    private function ms(float $seconds): string
    {
        return $seconds >= 1 ? number_format($seconds, 2).' s' : number_format($seconds * 1000, 1).' ms';
    }
}
