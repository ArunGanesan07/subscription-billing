<?php

namespace Tests\Feature;

use App\Billing\Period;
use App\Jobs\AggregateUsageChunk;
use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Merchant;
use App\Services\UsageAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class UsageAggregationTest extends TestCase
{
    use BuildsBillingFixtures, RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00'));
        $this->merchant = $this->merchantWithKey();
    }

    #[Test]
    public function it_rolls_events_up_into_one_row_per_customer_per_day(): void
    {
        [$a, $b] = Customer::factory()->for($this->merchant)->count(2)->create();

        $this->usage($a, '2026-09-18', 100, events: 4);
        $this->usage($a, '2026-09-19', 70, events: 2);
        $this->usage($b, '2026-09-19', 5);

        $written = app(UsageAggregator::class)->aggregate([$a->id, $b->id], new Period('2026-09-18', '2026-09-20'));

        $this->assertSame(3, $written);
        $this->assertDailyUsage($a, '2026-09-18', units: 100, events: 4);
        $this->assertDailyUsage($a, '2026-09-19', units: 70, events: 2);
        $this->assertDailyUsage($b, '2026-09-19', units: 5, events: 1);
    }

    #[Test]
    public function re_running_is_idempotent_and_late_events_converge(): void
    {
        $customer = Customer::factory()->for($this->merchant)->create();
        $aggregator = app(UsageAggregator::class);
        $window = new Period('2026-09-18', '2026-09-20');

        $this->usage($customer, '2026-09-19', 100);
        $aggregator->aggregate([$customer->id], $window);
        $aggregator->aggregate([$customer->id], $window);   // retry / overlapping run

        $this->assertDailyUsage($customer, '2026-09-19', units: 100, events: 1);

        // A late (backdated) event arrives; the next run overwrites with the new total.
        $this->usage($customer, '2026-09-19', 40);
        $aggregator->aggregate([$customer->id], $window);

        $this->assertDailyUsage($customer, '2026-09-19', units: 140, events: 2);
        $this->assertSame(1, DailyUsage::query()->count());
    }

    #[Test]
    public function it_only_touches_the_requested_customers_and_dates(): void
    {
        [$included, $excluded] = Customer::factory()->for($this->merchant)->count(2)->create();

        $this->usage($included, '2026-09-10', 10);    // outside the window
        $this->usage($included, '2026-09-19', 20);
        $this->usage($excluded, '2026-09-19', 30);

        app(UsageAggregator::class)->aggregate([$included->id], new Period('2026-09-18', '2026-09-20'));

        $this->assertSame(1, DailyUsage::query()->count());
        $this->assertDailyUsage($included, '2026-09-19', units: 20, events: 1);
    }

    #[Test]
    public function billing_run_dispatches_one_chunk_job_per_slice_of_customers(): void
    {
        Bus::fake();
        config(['billing.aggregation.chunk_size' => 2]);
        Customer::factory()->for($this->merchant)->count(5)->create();

        $this->artisan('billing:run')->assertSuccessful();

        Bus::assertBatched(function (PendingBatch $batch) {
            $sizes = $batch->jobs->map(fn (AggregateUsageChunk $job) => count($job->customerIds))->all();

            return $sizes === [2, 2, 1]
                && $batch->jobs->every(fn (AggregateUsageChunk $job) => $job->from === '2026-09-18'
                    && $job->to === '2026-09-20'
                    && $job->invoiceAsOf === '2026-09-20');
        });
    }

    #[Test]
    public function aggregate_only_runs_do_not_invoice(): void
    {
        Bus::fake();
        Customer::factory()->for($this->merchant)->create();

        $this->artisan('billing:run --aggregate-only')->assertSuccessful();

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->every(fn ($job) => $job->invoiceAsOf === null));
    }

    private function assertDailyUsage(Customer $customer, string $date, int $units, int $events): void
    {
        $row = DailyUsage::query()->where('customer_id', $customer->id)->whereDate('usage_date', $date)->first();

        $this->assertNotNull($row, "No daily_usage row for customer {$customer->id} on {$date}.");
        $this->assertSame([$units, $events], [$row->units, $row->event_count]);
        $this->assertSame($customer->merchant_id, $row->merchant_id);
    }
}
