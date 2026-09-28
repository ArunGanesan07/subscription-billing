<?php

namespace App\Services;

use App\Billing\Period;
use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\UsageEvent;

/**
 * Rolls raw usage_events up into daily_usage.
 *
 * Totals are recomputed from the source and written with an upsert that
 * overwrites (units = computed), never incremented (units = units + x). That
 * makes every run idempotent: re-running a day, overlapping windows, retries
 * after a crash, and late-arriving events all converge on the correct total
 * without a watermark to keep consistent.
 */
class UsageAggregator
{
    /**
     * @param  list<int>  $customerIds
     * @return int number of customer-day rows written
     */
    public function aggregate(array $customerIds, Period $period): int
    {
        if ($customerIds === []) {
            return 0;
        }

        // Only indexed columns are read here, so on MySQL this is an index-only
        // scan of (customer_id, usage_date, units).
        $totals = UsageEvent::query()->toBase()
            ->selectRaw('customer_id, usage_date, SUM(units) AS units, COUNT(*) AS event_count')
            ->whereIn('customer_id', $customerIds)
            ->whereBetween('usage_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->groupBy('customer_id', 'usage_date')
            ->get();

        if ($totals->isEmpty()) {
            return 0;
        }

        $merchantIds = Customer::query()->whereIn('id', $customerIds)->pluck('merchant_id', 'id');
        $now = now();

        $rows = $totals->map(fn (object $row) => [
            'merchant_id' => $merchantIds[$row->customer_id],
            'customer_id' => (int) $row->customer_id,
            'usage_date' => substr((string) $row->usage_date, 0, 10),
            'units' => (int) $row->units,
            'event_count' => (int) $row->event_count,
            'aggregated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, config('billing.aggregation.upsert_batch_size')) as $batch) {
            DailyUsage::query()->upsert(
                $batch,
                uniqueBy: ['customer_id', 'usage_date'],
                update: ['units', 'event_count', 'aggregated_at'],
            );
        }

        return count($rows);
    }
}
