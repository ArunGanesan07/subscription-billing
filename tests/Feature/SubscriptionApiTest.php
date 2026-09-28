<?php

namespace Tests\Feature;

use App\Enums\BillingInterval;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
{
    use BuildsBillingFixtures, RefreshDatabase;

    private Merchant $merchant;

    private Customer $customer;

    private Plan $growth;

    private Plan $scale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-10'));

        $this->merchant = $this->merchantWithKey();
        $this->customer = Customer::factory()->for($this->merchant)->create();
        $this->growth = $this->growthPlan($this->merchant);
        $this->scale = $this->scalePlan($this->merchant);
    }

    #[Test]
    public function a_customer_subscribes_with_a_pricing_snapshot(): void
    {
        $this->postJson("/api/v1/customers/{$this->customer->id}/subscriptions", ['plan_id' => $this->growth->id], $this->auth())
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.started_on', '2026-09-10')
            ->assertJsonPath('data.segments.0.base_price.amount', 300000)
            ->assertJsonPath('data.segments.0.ends_on', null);

        $this->postJson("/api/v1/customers/{$this->customer->id}/subscriptions", ['plan_id' => $this->scale->id], $this->auth())
            ->assertStatus(409)
            ->assertJsonPath('error', 'already_subscribed');
    }

    #[Test]
    public function upgrading_mid_cycle_closes_the_old_segment_and_opens_a_new_one(): void
    {
        $subscription = $this->subscribe($this->customer, $this->growth, '2026-09-01');

        $this->postJson("/api/v1/subscriptions/{$subscription->id}/change-plan", ['plan_id' => $this->scale->id], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.plan_id', $this->scale->id)
            ->assertJsonPath('data.segments.0.plan_name', 'Growth')
            ->assertJsonPath('data.segments.0.ends_on', '2026-09-09')
            ->assertJsonPath('data.segments.1.plan_name', 'Scale')
            ->assertJsonPath('data.segments.1.starts_on', '2026-09-10');
    }

    #[Test]
    public function a_change_on_the_segments_first_day_replaces_it_instead_of_leaving_a_zero_day_segment(): void
    {
        $subscription = $this->subscribe($this->customer, $this->growth, '2026-09-10');

        $this->postJson("/api/v1/subscriptions/{$subscription->id}/change-plan", ['plan_id' => $this->scale->id], $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data.segments')
            ->assertJsonPath('data.segments.0.plan_name', 'Scale');
    }

    #[Test]
    public function price_edits_do_not_rewrite_existing_segments(): void
    {
        $subscription = $this->subscribe($this->customer, $this->growth, '2026-09-01');

        $this->patchJson("/api/v1/plans/{$this->growth->id}", ['base_price' => 500000], $this->auth())->assertOk();

        $this->assertSame(300000, $subscription->segments()->sole()->base_price);
    }

    #[Test]
    public function plan_change_rules_are_enforced(): void
    {
        $subscription = $this->subscribe($this->customer, $this->growth, '2026-09-01');
        $yearly = Plan::factory()->for($this->merchant)->create(['code' => 'annual', 'billing_interval' => BillingInterval::Yearly]);
        $retired = Plan::factory()->for($this->merchant)->create(['code' => 'legacy', 'is_active' => false]);
        $foreignPlan = Plan::factory()->create();

        $cases = [
            [['plan_id' => $this->growth->id], 'same_plan'],
            [['plan_id' => $yearly->id], 'interval_mismatch'],
            [['plan_id' => $retired->id], 'plan_inactive'],
            [['plan_id' => $foreignPlan->id], 'plan_not_found'],
            [['plan_id' => $this->scale->id, 'effective_date' => '2026-09-11'], 'future_change_unsupported'],
            [['plan_id' => $this->scale->id, 'effective_date' => '2026-08-31'], 'effective_date_too_early'],
        ];

        foreach ($cases as [$payload, $error]) {
            $this->postJson("/api/v1/subscriptions/{$subscription->id}/change-plan", $payload, $this->auth())
                ->assertUnprocessable()
                ->assertJsonPath('error', $error);
        }
    }

    #[Test]
    public function a_change_cannot_be_backdated_into_an_invoiced_cycle(): void
    {
        $subscription = $this->subscribe($this->customer, $this->growth, '2026-08-01');
        $subscription->update(['billed_through' => '2026-08-31']);

        $this->travelTo(CarbonImmutable::parse('2026-09-02'));

        $this->postJson("/api/v1/subscriptions/{$subscription->id}/change-plan", ['plan_id' => $this->scale->id, 'effective_date' => '2026-08-20'], $this->auth())
            ->assertUnprocessable()
            ->assertJsonPath('error', 'period_already_invoiced');

        $this->postJson("/api/v1/subscriptions/{$subscription->id}/change-plan", ['plan_id' => $this->scale->id, 'effective_date' => '2026-09-01'], $this->auth())
            ->assertOk();
    }

    #[Test]
    public function other_tenants_resources_are_invisible(): void
    {
        $other = $this->merchantWithKey('mk_test_other_key');
        $theirCustomer = Customer::factory()->for($other)->create();
        $theirSubscription = $this->subscribe($theirCustomer, $this->growthPlan($other), '2026-09-01');

        $this->getJson("/api/v1/customers/{$theirCustomer->id}", $this->auth())->assertNotFound();
        $this->getJson("/api/v1/subscriptions/{$theirSubscription->id}", $this->auth())->assertNotFound();
        $this->postJson("/api/v1/subscriptions/{$theirSubscription->id}/cancel", [], $this->auth())->assertNotFound();
        $this->getJson("/api/v1/plans/{$this->growth->id}", $this->auth('mk_test_other_key'))->assertNotFound();
        $this->getJson("/api/v1/merchants/{$other->id}/dashboard", $this->auth())->assertForbidden();
    }
}
