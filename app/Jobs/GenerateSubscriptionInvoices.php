<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Services\InvoiceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Issues every settled, not-yet-invoiced cycle for one subscription. One job
 * per subscription isolates failures: a bad record retries on its own
 * instead of failing a whole chunk.
 */
class GenerateSubscriptionInvoices implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(
        public int $subscriptionId,
        public string $asOf,
    ) {
        $this->onQueue(config('billing.aggregation.queue'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("subscription:{$this->subscriptionId}"))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(InvoiceGenerator $generator): void
    {
        $subscription = Subscription::query()->find($this->subscriptionId);

        if ($subscription === null) {
            return;
        }

        $generator->generateDue($subscription, CarbonImmutable::parse($this->asOf));
    }
}
