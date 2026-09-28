<?php

namespace App\Support;

/**
 * Every cache key the application uses, in one place, so the invalidation
 * strategy can be audited without grepping for string literals.
 */
final class CacheKeys
{
    public static function plan(int $planId): string
    {
        return "plans:{$planId}";
    }

    public static function merchantPlans(int $merchantId): string
    {
        return "merchants:{$merchantId}:plans";
    }

    public static function merchantByApiKeyHash(string $hash): string
    {
        return "merchants:api-key:{$hash}";
    }

    public static function customerOwner(int $customerId): string
    {
        return "customers:{$customerId}:merchant";
    }

    public static function dashboard(int $merchantId, string $date): string
    {
        return "dashboard:{$merchantId}:{$date}";
    }

    public static function lastUsageRecordedAt(int $merchantId): string
    {
        return "usage:last-recorded-at:{$merchantId}";
    }

    public static function lastAggregationRun(): string
    {
        return 'billing:last-aggregation-run';
    }
}
