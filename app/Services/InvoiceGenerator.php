<?php

namespace App\Services;

use App\Billing\InvoiceCalculator;
use App\Billing\InvoiceDraft;
use App\Billing\InvoiceLineDraft;
use App\Billing\Period;
use App\Billing\SegmentTerms;
use App\Enums\InvoiceStatus;
use App\Models\DailyUsage;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a finished (and settled) billing cycle into a persisted invoice.
 *
 * A cycle is only invoiced once it is "settled": more than
 * `usage.max_backdate_days` after it ends, because until then a legitimately
 * late usage event could still land inside it. That rule means an issued
 * invoice never has to be amended for late usage.
 */
class InvoiceGenerator
{
    public function __construct(
        private readonly UsageAggregator $aggregator,
        private readonly InvoiceCalculator $calculator,
    ) {}

    /**
     * Generates every invoice that is due (catching up if runs were missed).
     *
     * @return Collection<int, Invoice>
     */
    public function generateDue(Subscription $subscription, CarbonInterface $today): Collection
    {
        $invoices = collect();

        while ($cycle = $this->nextDueCycle($subscription, $today)) {
            $invoices->push($this->generate($subscription, $cycle));
            $subscription->refresh();
        }

        return $invoices;
    }

    public function nextDueCycle(Subscription $subscription, CarbonInterface $today): ?Period
    {
        $from = $subscription->billed_through?->addDay() ?? $subscription->started_on;

        if ($subscription->ends_on !== null && $from->greaterThan($subscription->ends_on)) {
            return null;
        }

        $cycle = $subscription->billing_interval->cycleContaining($from);
        $settledAfter = $cycle->end->addDays(config('billing.usage.max_backdate_days'));

        return CarbonImmutable::parse($today)->startOfDay()->greaterThan($settledAfter) ? $cycle : null;
    }

    public function draft(Subscription $subscription, Period $cycle): InvoiceDraft
    {
        return $this->calculator->calculate(
            $cycle,
            $this->segmentTerms($subscription, $cycle),
            $this->dailyUsage($subscription->customer_id, $cycle),
        );
    }

    public function generate(Subscription $subscription, Period $cycle): Invoice
    {
        // The invoice is the source of truth for money, so rebuild the cycle's
        // rollup from raw events first rather than trusting that every nightly
        // aggregation run succeeded.
        $this->aggregator->aggregate([$subscription->customer_id], $cycle);

        $draft = $this->draft($subscription, $cycle);

        try {
            return $this->persist($subscription, $cycle, $draft);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with another worker (the row lock is a no-op on
            // SQLite); the unique index kept it to one invoice, so return it.
            return Invoice::query()
                ->where('subscription_id', $subscription->id)
                ->where('period_start', $cycle->start->toDateString())
                ->sole();
        }
    }

    private function persist(Subscription $subscription, Period $cycle, InvoiceDraft $draft): Invoice
    {
        return DB::transaction(function () use ($subscription, $cycle, $draft) {
            // Serialise concurrent generators for the same subscription.
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);

            $existing = Invoice::query()
                ->where('subscription_id', $locked->id)
                ->where('period_start', $cycle->start->toDateString())
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $invoice = Invoice::query()->create([
                'merchant_id' => $locked->merchant_id,
                'customer_id' => $locked->customer_id,
                'subscription_id' => $locked->id,
                'period_start' => $cycle->start->toDateString(),
                'period_end' => $cycle->end->toDateString(),
                'currency' => $locked->merchant()->value('currency'),
                'total' => $draft->total(),
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
            ]);

            $invoice->lines()->createMany(array_map(fn (InvoiceLineDraft $line) => [
                'plan_id' => $line->planId,
                'type' => $line->type,
                'description' => $line->description,
                'period_start' => $line->period->start->toDateString(),
                'period_end' => $line->period->end->toDateString(),
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
                'amount' => $line->amount,
                'meta' => $line->meta,
            ], $draft->lines));

            if ($locked->billed_through === null || $locked->billed_through->lessThan($cycle->end)) {
                $locked->update(['billed_through' => $cycle->end->toDateString()]);
            }

            return $invoice;
        });
    }

    /**
     * @return list<SegmentTerms>
     */
    private function segmentTerms(Subscription $subscription, Period $cycle): array
    {
        return SubscriptionSegment::query()
            ->with('plan')
            ->where('subscription_id', $subscription->id)
            ->overlapping($cycle)
            ->orderBy('starts_on')
            ->get()
            ->map(fn (SubscriptionSegment $segment) => SegmentTerms::fromModel($segment))
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function dailyUsage(int $customerId, Period $cycle): array
    {
        return DailyUsage::query()->toBase()
            ->where('customer_id', $customerId)
            ->whereBetween('usage_date', [$cycle->start->toDateString(), $cycle->end->toDateString()])
            ->pluck('units', 'usage_date')
            ->mapWithKeys(fn ($units, $date) => [substr((string) $date, 0, 10) => (int) $units])
            ->all();
    }
}
