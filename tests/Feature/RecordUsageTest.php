<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

class RecordUsageTest extends TestCase
{
    use BuildsBillingFixtures, RefreshDatabase;

    private Merchant $merchant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00'));

        $this->merchant = $this->merchantWithKey();
        $this->customer = Customer::factory()->for($this->merchant)->create();
    }

    #[Test]
    public function it_records_a_usage_event(): void
    {
        $this->post('/api/v1/usage', $this->payload(), $this->auth() + ['Idempotency-Key' => 'evt-1'])
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.customer_id', $this->customer->id)
            ->assertJsonPath('data.units', 25)
            ->assertJsonPath('data.usage_date', '2026-09-20');

        $this->assertDatabaseHas('usage_events', [
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'idempotency_key' => 'evt-1',
            'units' => 25,
        ]);
    }

    #[Test]
    public function a_retried_request_is_not_double_counted(): void
    {
        $headers = $this->auth() + ['Idempotency-Key' => 'evt-retry'];

        $first = $this->post('/api/v1/usage', $this->payload(), $headers)->assertCreated();
        $this->post('/api/v1/usage', $this->payload(), $headers)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, UsageEvent::query()->count());
        $this->assertSame(25, (int) UsageEvent::query()->sum('units'));
    }

    #[Test]
    public function reusing_a_key_for_a_different_payload_is_a_conflict(): void
    {
        $headers = $this->auth() + ['Idempotency-Key' => 'evt-reused'];

        $this->post('/api/v1/usage', $this->payload(), $headers)->assertCreated();
        $this->post('/api/v1/usage', $this->payload(['units' => 999]), $headers)
            ->assertStatus(409)
            ->assertJsonPath('error', 'idempotency_key_reused');

        $this->assertSame(25, (int) UsageEvent::query()->sum('units'));
    }

    #[Test]
    public function idempotency_keys_are_scoped_per_merchant(): void
    {
        $other = $this->merchantWithKey('mk_test_other_key');
        $otherCustomer = Customer::factory()->for($other)->create();

        $this->post('/api/v1/usage', $this->payload(), $this->auth() + ['Idempotency-Key' => 'shared'])->assertCreated();
        $this->post('/api/v1/usage', $this->payload(['customer_id' => $otherCustomer->id]), $this->auth('mk_test_other_key') + ['Idempotency-Key' => 'shared'])->assertCreated();

        $this->assertSame(2, UsageEvent::query()->count());
    }

    #[Test]
    public function an_idempotency_key_is_required(): void
    {
        $this->post('/api/v1/usage', $this->payload(), $this->auth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    #[Test]
    public function it_rejects_another_merchants_customer_like_an_unknown_one(): void
    {
        $foreign = Customer::factory()->create();   // belongs to a different merchant

        foreach ([$foreign->id, 999999] as $customerId) {
            $this->post('/api/v1/usage', $this->payload(['customer_id' => $customerId]), $this->auth() + ['Idempotency-Key' => "k-{$customerId}"])
                ->assertUnprocessable()
                ->assertJsonPath('errors.customer_id.0', 'Unknown customer.');
        }

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function it_validates_units_and_dates(): void
    {
        $cases = [
            'zero units' => [['units' => 0], 'units'],
            'negative units' => [['units' => -5], 'units'],
            'fractional units' => [['units' => 1.5], 'units'],
            'huge units' => [['units' => 10_000_000], 'units'],
            'future date' => [['usage_date' => '2026-09-21'], 'usage_date'],
            'beyond backdate window' => [['usage_date' => '2026-09-17'], 'usage_date'],
            'bad date format' => [['usage_date' => '20/09/2026'], 'usage_date'],
        ];

        foreach ($cases as $name => [$override, $field]) {
            $this->post('/api/v1/usage', $this->payload($override), $this->auth() + ['Idempotency-Key' => md5($name)])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        // The edge of the backdate window (2 days) is accepted.
        $this->post('/api/v1/usage', $this->payload(['usage_date' => '2026-09-18']), $this->auth() + ['Idempotency-Key' => 'edge'])
            ->assertCreated();
    }

    #[Test]
    public function it_requires_a_valid_api_key(): void
    {
        $this->post('/api/v1/usage', $this->payload(), ['Accept' => 'application/json', 'Idempotency-Key' => 'x'])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'unauthenticated');

        $this->post('/api/v1/usage', $this->payload(), $this->auth('mk_wrong') + ['Idempotency-Key' => 'x'])
            ->assertUnauthorized();
    }

    #[Test]
    public function it_is_rate_limited_per_api_key(): void
    {
        config(['billing.usage.rate_limit_per_minute' => 3]);

        for ($i = 1; $i <= 3; $i++) {
            $this->post('/api/v1/usage', $this->payload(), $this->auth() + ['Idempotency-Key' => "rl-{$i}"])->assertCreated();
        }

        $this->post('/api/v1/usage', $this->payload(), $this->auth() + ['Idempotency-Key' => 'rl-4'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        // A different merchant has its own bucket.
        $other = $this->merchantWithKey('mk_test_other_key');
        $otherCustomer = Customer::factory()->for($other)->create();
        $this->post('/api/v1/usage', $this->payload(['customer_id' => $otherCustomer->id]), $this->auth('mk_test_other_key') + ['Idempotency-Key' => 'rl-other'])
            ->assertCreated();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'customer_id' => $this->customer->id,
            'units' => 25,
            'usage_date' => '2026-09-20',
        ];
    }
}
