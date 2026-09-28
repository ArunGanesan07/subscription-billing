<?php

namespace App\Jobs;

use App\Billing\Period;
use App\Models\Subscription;
use App\Services\InvoiceGenerator;
use App\Services\UsageAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One chunk of a billing run: refreshes daily_usage for a slice of customers
 * and, if this is an invoicing run, fans out one invoice job per subscription
 * that has a settled cycle waiting to be billed.
 *
 * Safe to retry: aggregation overwrites totals, and invoice generation is
 * guarded by a row lock and a unique (subscription_id, period_start) index.
 */
class AggregateUsageChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    /**
     * @param  list<int>  $customerIds
     */
    public function __construct(
        public array $customerIds,
        public string $from,
        public string $to,
        public ?string $invoiceAsOf = null,
    ) {
        $this->onQueue(config('billing.aggregation.queue'));
    }

    public function handle(UsageAggregator $aggregator, InvoiceGenerator $invoices): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $aggregator->aggregate($this->customerIds, new Period($this->from, $this->to));

        if ($this->invoiceAsOf === null) {
            return;
        }

        $asOf = CarbonImmutable::parse($this->invoiceAsOf);

        $jobs = $this->subscriptionsPossiblyDue()
            ->filter(fn (Subscription $s) => $invoices->nextDueCycle($s, $asOf) !== null)
            ->map(fn (Subscription $s) => new GenerateSubscriptionInvoices($s->id, $this->invoiceAsOf))
            ->values()
            ->all();

        if ($jobs === []) {
            return;
        }

        if ($this->batch()) {
            $this->batch()->add($jobs);

            return;
        }

        foreach ($jobs as $job) {
            dispatch($job);
        }
    }

    /**
     * Cheap SQL pre-filter; InvoiceGenerator::nextDueCycle() then makes the
     * exact call in PHP so only subscriptions with a settled cycle get a job.
     *
     * @return Collection<int, Subscription>
     */
    private function subscriptionsPossiblyDue(): Collection
    {
        // A cycle is due once asOf > cycle_end + backdate. The next cycle ends
        // at least one day after billed_through, so anything billed through
        // (asOf − backdate − 1) or later cannot be due yet.
        $cutoff = CarbonImmutable::parse($this->invoiceAsOf)
            ->subDays(config('billing.usage.max_backdate_days') + 1)
            ->toDateString();

        return Subscription::query()
            ->whereIn('customer_id', $this->customerIds)
            ->where(fn (Builder $q) => $q->whereNull('billed_through')->orWhere('billed_through', '<', $cutoff))
            ->where(fn (Builder $q) => $q
                ->whereNull('ends_on')
                ->orWhereNull('billed_through')
                ->orWhereColumn('billed_through', '<', 'ends_on'))
            ->get(['id', 'billing_interval', 'started_on', 'ends_on', 'billed_through']);
    }
}
