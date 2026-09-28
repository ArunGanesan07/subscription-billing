<?php

namespace Tests\Feature;

use App\Billing\Period;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Services\SubscriptionService;
use App\Services\UsageAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * "Today" is 11 Sep 2026, so the current month holds 10 complete days
 * (1–10 Sep), compared against 1–10 Aug for churn.
 */
class DashboardTest extends TestCase
{
    use BuildsBillingFixtures, RefreshDatabase;

    private Merchant $merchant;

    private Plan $growth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-11 09:00'));

        $this->merchant = $this->merchantWithKey();
        $this->growth = $this->growthPlan($this->merchant);
    }

    #[Test]
    public function it_ranks_the_top_five_customers_by_usage_this_month(): void
    {
        $customers = collect(range(1, 7))->map(function (int $i) {
            $customer = Customer::factory()->for($this->merchant)->create(['name' => "Customer {$i}"]);
            $this->subscribe($customer, $this->growth, '2026-08-01');
            $this->usage($customer, '2026-09-05', $i * 100);
            $this->usage($customer, '2026-08-20', 100000);    // last month: must not count

            return $customer;
        });

        $top = $this->dashboard()->json('data.top_customers');

        $this->assertSame(['Customer 7', 'Customer 6', 'Customer 5', 'Customer 4', 'Customer 3'], array_column($top, 'name'));
        $this->assertSame(700, $top[0]['usage']);
        $this->assertSame(3000, $top[0]['allowance']);
        $this->assertEquals(23.3, $top[0]['percent_of_allowance']);
    }

    #[Test]
    public function it_projects_overage_revenue_for_the_current_cycle(): void
    {
        $customer = Customer::factory()->for($this->merchant)->create();
        $this->subscribe($customer, $this->growth, '2026-09-01');
        // 200/day for 11 days (1–11 Sep) → 6,000 projected over 30 days → 3,000 over × 50 paise.
        $this->dailyUsage($customer, '2026-09-01', '2026-09-11', 200);

        $light = Customer::factory()->for($this->merchant)->create();
        $this->subscribe($light, $this->growth, '2026-09-01');
        $this->usage($light, '2026-09-03', 50);             // well under allowance → contributes 0

        $data = $this->dashboard()->json('data');

        $this->assertSame(150000, $data['projected_overage_revenue']['amount']);
        $this->assertSame('₹1,500.00', $data['projected_overage_revenue']['formatted']);
        $this->assertSame(2200 + 50, $data['current_cycle']['usage']);
        $this->assertSame(6000, $data['current_cycle']['allowance']);
        $this->assertSame(2, $data['current_cycle']['active_subscriptions']);
    }

    #[Test]
    public function projection_respects_a_mid_cycle_upgrade(): void
    {
        $scale = $this->scalePlan($this->merchant);
        $customer = Customer::factory()->for($this->merchant)->create();

        $this->travelTo(CarbonImmutable::parse('2026-09-01'));
        $subscription = $this->subscribe($customer, $this->growth, '2026-09-01');
        $this->dailyUsage($customer, '2026-09-01', '2026-09-05', 400);   // 2,000 on Growth (1–5 Sep)

        $this->travelTo(CarbonImmutable::parse('2026-09-06'));
        app(SubscriptionService::class)->changePlan($subscription, $scale);
        $this->dailyUsage($customer, '2026-09-06', '2026-09-11', 1000);  // 6,000 on Scale (6–11 Sep)
        $this->travelTo(CarbonImmutable::parse('2026-09-11 09:00'));

        // Growth 1–5 Sep (closed): allowance 500 → 1,500 over × 50 = 75,000
        // Scale 6–30 Sep: 6,000 in 6 of 25 days → 25,000 projected; allowance 8,333 → 16,667 × 20 = 333,340
        $this->assertSame(75000 + 333340, $this->dashboard()->json('data.projected_overage_revenue.amount'));
    }

    #[Test]
    public function it_flags_customers_whose_usage_dropped_more_than_half_month_over_month(): void
    {
        $scenarios = [
            'Nova Traders' => [1000, 380],   // −62% → flagged
            'QuickMart' => [1000, 450],      // −55% → flagged
            'Steady Co' => [1000, 600],      // −40% → fine
            'Growing Co' => [500, 900],      // up → fine
            'Tiny Co' => [60, 0],            // below the 100-unit baseline → ignored as noise
            'Gone Quiet' => [800, 0],        // −100% → flagged (the strongest signal)
        ];

        foreach ($scenarios as $name => [$august, $september]) {
            $customer = Customer::factory()->for($this->merchant)->create(['name' => $name]);
            $this->subscribe($customer, $this->growth, '2026-07-01');
            $this->usage($customer, '2026-08-05', $august);
            $this->usage($customer, '2026-08-25', 99999);   // after the comparable window: ignored
            if ($september > 0) {
                $this->usage($customer, '2026-09-05', $september);
            }
        }

        $cancelled = Customer::factory()->for($this->merchant)->create(['name' => 'Already Churned']);
        $subscription = $this->subscribe($cancelled, $this->growth, '2026-07-01');
        $this->usage($cancelled, '2026-08-05', 1000);
        app(SubscriptionService::class)->cancel($subscription);

        $churn = $this->dashboard()->json('data.churn_risk');

        $this->assertSame(['start' => '2026-09-01', 'end' => '2026-09-10'], $churn['window']['current']);
        $this->assertSame(['start' => '2026-08-01', 'end' => '2026-08-10'], $churn['window']['previous']);
        $this->assertSame(['Gone Quiet', 'Nova Traders', 'QuickMart'], array_column($churn['customers'], 'name'));
        $this->assertEquals([100, 62, 55], array_column($churn['customers'], 'drop_percent'));
    }

    #[Test]
    public function it_returns_a_zero_filled_30_day_trend_and_ignores_other_tenants(): void
    {
        $customer = Customer::factory()->for($this->merchant)->create();
        $this->subscribe($customer, $this->growth, '2026-08-01');
        $this->usage($customer, '2026-09-10', 123);

        $other = Merchant::factory()->create();
        $theirs = Customer::factory()->for($other)->create();
        $this->subscribe($theirs, $this->growthPlan($other), '2026-08-01');
        $this->usage($theirs, '2026-09-10', 99999);

        $data = $this->dashboard()->json('data');

        $this->assertCount(30, $data['daily_trend']);
        $this->assertSame(['date' => '2026-08-13', 'units' => 0], $data['daily_trend'][0]);
        $this->assertSame(['date' => '2026-09-10', 'units' => 123], $data['daily_trend'][28]);
        $this->assertSame([$customer->id], array_column($data['top_customers'], 'customer_id'));
        $this->assertSame([['plan_id' => $this->growth->id, 'name' => 'Growth', 'billing_interval' => 'monthly', 'subscribers' => 1]], $data['active_plans']);
    }

    private function dashboard()
    {
        // The dashboard reads the rollup; aggregate everything first.
        app(UsageAggregator::class)->aggregate(
            Customer::query()->pluck('id')->all(),
            new Period('2026-07-01', '2026-09-30'),
        );

        return $this->getJson("/api/v1/merchants/{$this->merchant->id}/dashboard", $this->auth())->assertOk();
    }
}
