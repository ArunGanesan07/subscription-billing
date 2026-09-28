<?php

namespace App\Console\Commands;

use App\Jobs\AggregateUsageChunk;
use App\Models\Customer;
use App\Support\CacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

class RunBilling extends Command
{
    protected $signature = 'billing:run
        {--date= : Run as if today were this date (Y-m-d); useful for backfills and demos}
        {--aggregate-only : Refresh daily usage without generating invoices}
        {--sync : Run every job in this process instead of on the queue}';

    protected $description = 'Aggregate recent usage in chunks and issue invoices for settled billing cycles';

    public function handle(): int
    {
        if ($this->option('sync')) {
            config(['queue.default' => 'sync']);
        }

        $today = CarbonImmutable::parse($this->option('date') ?? 'today')->startOfDay();
        // Re-aggregate the whole late-arrival window: events may be backdated
        // this far, and re-aggregation is idempotent.
        $from = $today->subDays(config('billing.usage.max_backdate_days'));
        $invoiceAsOf = $this->option('aggregate-only') ? null : $today->toDateString();

        $jobs = [];
        Customer::query()->select('id')->chunkById(
            config('billing.aggregation.chunk_size'),
            function ($customers) use (&$jobs, $from, $today, $invoiceAsOf) {
                $jobs[] = new AggregateUsageChunk(
                    $customers->pluck('id')->all(),
                    $from->toDateString(),
                    $today->toDateString(),
                    $invoiceAsOf,
                );
            },
        );

        if ($jobs === []) {
            $this->info('No customers to process.');

            return self::SUCCESS;
        }

        $asOf = $today->toDateString();

        $batch = Bus::batch($jobs)
            ->name(($invoiceAsOf ? 'billing-run ' : 'usage-aggregation ').$asOf)
            ->allowFailures()
            ->onQueue(config('billing.aggregation.queue'))
            ->finally(function (Batch $batch) use ($asOf) {
                Cache::forever(CacheKeys::lastAggregationRun(), [
                    'batch_id' => $batch->id,
                    'name' => $batch->name,
                    'as_of' => $asOf,
                    'finished_at' => now()->toIso8601String(),
                    'total_jobs' => $batch->totalJobs,
                    'failed_jobs' => $batch->failedJobs,
                ]);
            })
            ->dispatch();

        $this->info(sprintf(
            '%s batch %s: %d chunk job(s), window %s → %s%s',
            $this->option('sync') ? 'Ran' : 'Dispatched',
            $batch->id,
            count($jobs),
            $from->toDateString(),
            $today->toDateString(),
            $invoiceAsOf ? ', invoicing settled cycles' : ' (aggregate only)',
        ));

        return self::SUCCESS;
    }
}
