<?php

namespace Tests\Feature;

use App\Enums\InvoiceLineType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Services\InvoiceGenerator;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * End-to-end: raw events → queued chunked run → persisted invoice.
 * Plans: Growth ₹3,000 / 3,000 units / 50 paise; Scale ₹9,000 / 10,000 units / 20 paise.
 */
class InvoiceGenerationTest extends TestCase
{
    use BuildsBillingFixtures, RefreshDatabase;

    private Merchant $merchant;

    private Customer $customer;

    private Plan $growth;

    private Plan $scale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->merchantWithKey();
        $this->customer = Customer::factory()->for($this->merchant)->create();
        $this->growth = $this->growthPlan($this->merchant);
        $this->scale = $this->scalePlan($this->merchant);
    }

    #[Test]
    public function mid_cycle_start_then_upgrade_is_billed_per_segment_with_proration(): void
    {
        // Subscribes on 11 Sep to Growth, upgrades to Scale on 21 Sep.
        $this->travelTo(CarbonImmutable::parse('2026-09-11'));
        $subscription = $this->subscribe($this->customer, $this->growth, '2026-09-11');
        $this->usage($this->customer, '2026-09-05', 5000);                             // before start: ignored
        $this->dailyUsage($this->customer, '2026-09-11', '2026-09-20', 150);           // 1,500 on Growth

        $this->travelTo(CarbonImmutable::parse('2026-09-21'));
        app(SubscriptionService::class)->changePlan($subscription, $this->scale);
        $this->dailyUsage($this->customer, '2026-09-21', '2026-09-30', 700);           // 7,000 on Scale

        // 30 Sep + 2-day settlement window → billable from 3 Oct.
        $this->travelTo(CarbonImmutable::parse('2026-10-03 00:30'));
        $this->artisan('billing:run --sync')->assertSuccessful();

        $invoice = Invoice::query()->with('lines')->sole();
        $this->assertSame(['2026-09-01', '2026-09-30'], [$invoice->period_start->toDateString(), $invoice->period_end->toDateString()]);

        // Growth 11–20 Sep: 10/30 days → base 100000, allowance 1000, 500 over × 50 = 25000
        // Scale  21–30 Sep: 10/30 days → base 300000, allowance 3333, 3667 over × 20 = 73340
        $lines = $invoice->lines->map(fn ($l) => [$l->type, $l->plan_id, $l->quantity, $l->amount])->all();
        $this->assertEquals([
            [InvoiceLineType::Base, $this->growth->id, 10, 100000],
            [InvoiceLineType::Overage, $this->growth->id, 500, 25000],
            [InvoiceLineType::Base, $this->scale->id, 10, 300000],
            [InvoiceLineType::Overage, $this->scale->id, 3667, 73340],
        ], $lines);
        $this->assertSame(498340, $invoice->total);
        $this->assertSame('2026-09-30', $subscription->fresh()->billed_through->toDateString());
    }

    #[Test]
    public function a_cycle_is_not_invoiced_until_the_late_usage_window_has_passed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01'));
        $this->subscribe($this->customer, $this->growth, '2026-09-01');

        foreach (['2026-10-01', '2026-10-02'] as $day) {
            $this->travelTo(CarbonImmutable::parse($day));
            $this->artisan('billing:run --sync')->assertSuccessful();
            $this->assertSame(0, Invoice::query()->count(), "Invoiced too early on {$day}.");
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-03'));
        $this->artisan('billing:run --sync')->assertSuccessful();
        $this->assertSame(1, Invoice::query()->count());
    }

    #[Test]
    public function usage_arriving_late_but_within_the_window_is_on_the_invoice(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01'));
        $this->subscribe($this->customer, $this->growth, '2026-09-01');
        $this->dailyUsage($this->customer, '2026-09-01', '2026-09-30', 100);   // exactly the allowance

        // Nightly aggregation already ran for 30 Sep…
        $this->travelTo(CarbonImmutable::parse('2026-10-01'));
        $this->artisan('billing:run --sync');

        // …then a backdated event for 30 Sep arrives on 2 Oct.
        $this->travelTo(CarbonImmutable::parse('2026-10-02'));
        $this->usage($this->customer, '2026-09-30', 10);

        $this->travelTo(CarbonImmutable::parse('2026-10-03'));
        $this->artisan('billing:run --sync');

        $this->assertSame(300000 + 10 * 50, Invoice::query()->sole()->total);
    }

    #[Test]
    public function generating_twice_never_creates_a_second_invoice(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01'));
        $subscription = $this->subscribe($this->customer, $this->growth, '2026-09-01');
        $this->travelTo(CarbonImmutable::parse('2026-10-05'));

        $generator = app(InvoiceGenerator::class);
        $cycle = $subscription->billing_interval->cycleContaining(CarbonImmutable::parse('2026-09-15'));

        $first = $generator->generate($subscription, $cycle);
        $second = $generator->generate($subscription->fresh(), $cycle);     // e.g. a retried job
        $this->artisan('billing:run --sync');                              // and a full run on top

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Invoice::query()->count());
    }

    #[Test]
    public function missed_runs_catch_up_one_invoice_per_cycle(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15'));
        $this->subscribe($this->customer, $this->growth, '2026-07-15');

        $this->travelTo(CarbonImmutable::parse('2026-10-10'));
        $this->artisan('billing:run --sync');

        $invoices = Invoice::query()->orderBy('period_start')->get();
        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01'], $invoices->map(fn ($i) => $i->period_start->toDateString())->all());
        $this->assertSame([164516, 300000, 300000], $invoices->pluck('total')->all());   // July prorated 17/31
    }

    #[Test]
    public function a_cancelled_subscription_gets_a_final_prorated_invoice_and_then_nothing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01'));
        $subscription = $this->subscribe($this->customer, $this->growth, '2026-09-01');

        $this->travelTo(CarbonImmutable::parse('2026-09-10'));
        app(SubscriptionService::class)->cancel($subscription);             // last billable day: 10 Sep

        $this->travelTo(CarbonImmutable::parse('2026-12-10'));
        $this->artisan('billing:run --sync');

        $this->assertSame(100000, Invoice::query()->sole()->total);        // 10/30 of ₹3,000
    }
}
