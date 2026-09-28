<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    |
    | All money is stored as integers in the currency's minor unit (paise for
    | INR). Per-unit overage rates may be fractional minor units (e.g. 0.5
    | paise per API call), so they are stored as DECIMAL(18,6).
    |
    */

    'default_currency' => env('BILLING_CURRENCY', 'INR'),

    'usage' => [
        // How far back a usage_date may be. This is also the "settlement
        // window": a cycle is invoiced only once no more usage can land in it.
        'max_backdate_days' => (int) env('USAGE_MAX_BACKDATE_DAYS', 2),

        'max_units_per_event' => (int) env('USAGE_MAX_UNITS_PER_EVENT', 1_000_000),

        // Requests per minute, per merchant API key, on POST /usage.
        'rate_limit_per_minute' => (int) env('USAGE_RATE_LIMIT_PER_MINUTE', 120),
    ],

    'aggregation' => [
        // Customers per queued chunk job.
        'chunk_size' => (int) env('BILLING_CHUNK_SIZE', 500),

        // Rows per upsert statement when writing daily aggregates.
        'upsert_batch_size' => 1000,

        'queue' => env('BILLING_QUEUE', 'default'),
    ],

    'cache' => [
        // Safety-net TTLs. Correctness comes from explicit invalidation on
        // write (see App\Observers); the TTL only bounds staleness if an
        // invalidation is ever missed.
        'plan_ttl' => (int) env('PLAN_CACHE_TTL', 600),
        'lookup_ttl' => (int) env('LOOKUP_CACHE_TTL', 600),
        'dashboard_ttl' => (int) env('DASHBOARD_CACHE_TTL', 60),
    ],

    'dashboard' => [
        'top_customers' => 5,
        'trend_days' => 30,
        // Usage must drop by more than this fraction month-over-month.
        'churn_drop_threshold' => 0.5,
        // Ignore customers whose previous-month usage was too small to be meaningful.
        'churn_min_baseline_units' => (int) env('CHURN_MIN_BASELINE_UNITS', 100),
    ],

];
