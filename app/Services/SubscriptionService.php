<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Exceptions\BillingException;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Subscription lifecycle. Every change is expressed as segments:
 *
 *   subscribe    → open the first segment
 *   change plan  → close the current segment the day before the change and
 *                  open a new one with the new plan's pricing snapshot
 *   cancel       → close the current segment
 *
 * Changes take effect from the start of the given day (usage is daily, so
 * there is nothing finer to split on). Invariants are re-checked under a row
 * lock so two concurrent requests can't both succeed.
 */
class SubscriptionService
{
    public function subscribe(Customer $customer, Plan $plan, ?CarbonInterface $startsOn = null): Subscription
    {
        $startsOn = $this->day($startsOn);
        $this->assertPlanUsableBy($plan, $customer->merchant_id);

        return DB::transaction(function () use ($customer, $plan, $startsOn) {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $hasActive = Subscription::query()
                ->where('customer_id', $customer->id)
                ->where('status', SubscriptionStatus::Active)
                ->exists();

            if ($hasActive) {
                throw BillingException::conflict('already_subscribed', 'Customer already has an active subscription. Change its plan instead.');
            }

            $subscription = Subscription::query()->create([
                'merchant_id' => $customer->merchant_id,
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'billing_interval' => $plan->billing_interval,
                'status' => SubscriptionStatus::Active,
                'started_on' => $startsOn->toDateString(),
            ]);

            $this->openSegment($subscription, $plan, $startsOn);

            return $subscription->load('currentSegment.plan');
        });
    }

    public function changePlan(Subscription $subscription, Plan $plan, ?CarbonInterface $effectiveOn = null): Subscription
    {
        $effectiveOn = $this->day($effectiveOn);
        $this->assertPlanUsableBy($plan, $subscription->merchant_id);

        return DB::transaction(function () use ($subscription, $plan, $effectiveOn) {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $current = $subscription->currentSegment()->firstOrFail();

            $this->assertActive($subscription);

            if ($plan->id === $subscription->plan_id) {
                throw new BillingException('same_plan', 'Subscription is already on this plan.');
            }

            if ($plan->billing_interval !== $subscription->billing_interval) {
                throw new BillingException('interval_mismatch', 'Plan changes must keep the same billing interval.');
            }

            if ($effectiveOn->greaterThan(CarbonImmutable::today())) {
                throw new BillingException('future_change_unsupported', 'Scheduled (future-dated) plan changes are not supported yet.');
            }

            $this->assertNotInvoiced($subscription, $effectiveOn);

            if ($effectiveOn->lessThan($current->starts_on)) {
                throw new BillingException('effective_date_too_early', 'A plan change cannot take effect before the current plan started ('.$current->starts_on->toDateString().').');
            }

            if ($effectiveOn->equalTo($current->starts_on)) {
                // Changed on the same day the current segment began: there is
                // no time on the old plan to bill, so replace it in place.
                $current->snapshotPricing($plan)->save();
            } else {
                $current->update(['ends_on' => $effectiveOn->subDay()->toDateString()]);
                $this->openSegment($subscription, $plan, $effectiveOn);
            }

            $subscription->update(['plan_id' => $plan->id]);

            return $subscription->load('currentSegment.plan');
        });
    }

    public function cancel(Subscription $subscription, ?CarbonInterface $endsOn = null): Subscription
    {
        $endsOn = $this->day($endsOn);

        return DB::transaction(function () use ($subscription, $endsOn) {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $current = $subscription->currentSegment()->firstOrFail();

            $this->assertActive($subscription);

            if ($endsOn->greaterThan(CarbonImmutable::today())) {
                throw new BillingException('future_change_unsupported', 'Cancelling at a future date is not supported yet.');
            }

            if ($endsOn->lessThan($current->starts_on)) {
                throw new BillingException('effective_date_too_early', 'Cannot cancel before the current plan started.');
            }

            $this->assertNotInvoiced($subscription, $endsOn->addDay());

            $current->update(['ends_on' => $endsOn->toDateString()]);
            $subscription->update([
                'status' => SubscriptionStatus::Cancelled,
                'ends_on' => $endsOn->toDateString(),
            ]);

            return $subscription->load('currentSegment.plan');
        });
    }

    private function openSegment(Subscription $subscription, Plan $plan, CarbonImmutable $startsOn): SubscriptionSegment
    {
        $segment = new SubscriptionSegment(['starts_on' => $startsOn->toDateString()]);
        $segment->snapshotPricing($plan);
        $subscription->segments()->save($segment);

        return $segment;
    }

    private function assertPlanUsableBy(Plan $plan, int $merchantId): void
    {
        if ($plan->merchant_id !== $merchantId) {
            // Same response as a missing plan: don't reveal other tenants' plans.
            throw new BillingException('plan_not_found', 'Plan not found.');
        }

        if (! $plan->is_active) {
            throw new BillingException('plan_inactive', 'This plan is no longer available.');
        }
    }

    private function assertActive(Subscription $subscription): void
    {
        if (! $subscription->isActive()) {
            throw new BillingException('subscription_inactive', 'Subscription is not active.');
        }
    }

    /** A change dated inside an already-invoiced cycle would alter an issued invoice. */
    private function assertNotInvoiced(Subscription $subscription, CarbonImmutable $date): void
    {
        if ($subscription->billed_through !== null && $date->lessThanOrEqualTo($subscription->billed_through)) {
            throw new BillingException('period_already_invoiced', 'That date falls in a cycle that has already been invoiced.');
        }
    }

    private function day(?CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date ?? CarbonImmutable::today())->startOfDay();
    }
}
